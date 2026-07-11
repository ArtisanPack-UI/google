---
title: OAuth Flow
---

# OAuth Flow

The package implements the **OAuth 2.0 Authorization Code flow with PKCE** end-to-end. Users visit a "Connect Google" link, get redirected to Google's consent screen, come back to a package-owned callback, and land in your app with a persisted `GoogleConnection` row.

This page walks through each leg. See the sub-pages for deeper coverage of specific stages.

## Routes

The service provider mounts five routes under `google.routes.prefix` (default `/google/auth`):

| Route | Method | Name | Purpose |
|---|---|---|---|
| `/connect` | GET | `google.auth.connect` | Redirect to Google's consent screen. |
| `/callback` | GET | `google.auth.callback` | Handle Google's redirect back. |
| `/reauthorize` | GET | `google.auth.reauthorize` | Incremental consent for scopes added since the initial connection. |
| `/disconnect` | POST | `google.auth.disconnect` | Mark the current user's connection disconnected. |
| `/status` | GET | `google.auth.status` | JSON status payload consumed by the React and Vue UIs. |

All five are configured with `web` middleware by default. All five expect an authenticated user (the controller `abort( 401 )`s on `null`). See [[Installation/Configuration#Routes|Configuration → Routes]] for how to customize.

Set `google.routes.enabled = false` to skip the built-in routes entirely (useful if your app wants to bind the manager services but use its own controller / URL structure).

## The flow, end to end

```
┌──────────────┐         ┌──────────────────┐         ┌─────────────┐
│  Your app    │         │  Package routes  │         │   Google    │
└──────┬───────┘         └────────┬─────────┘         └──────┬──────┘
       │                          │                          │
       │  User clicks "Connect"   │                          │
       │─────────────────────────>│  GET /connect            │
       │                          │                          │
       │                          │  Build authorize URL     │
       │                          │  Store state + verifier  │
       │                          │  in session              │
       │                          │─────────────────────────>│
       │                          │                          │  User approves scopes
       │                          │                          │
       │                          │<─────────────────────────│  GET /callback?code=…&state=…
       │                          │                          │
       │                          │  Verify state            │
       │                          │  POST /token             │
       │                          │─────────────────────────>│
       │                          │<─────────────────────────│  { access_token, refresh_token, ... }
       │                          │                          │
       │                          │  Extract id_token claims │
       │                          │  Persist GoogleConnection│
       │                          │                          │
       │<─────────────────────────│  redirect_after_connect  │
       │                          │                          │
```

## Connect

`GoogleAuthController::connect()` is the entry point. It resolves the authenticated user, calls `OAuthManager::authorizationUrl( $userId )`, and returns a `redirect()->away()` to the built URL.

`authorizationUrl()` builds the URL with:

- `client_id`, `redirect_uri` from the configured [[Credential Drivers|credential driver]].
- `scope` = the full de-duplicated union of everything the [[Scopes|scope registry]] returns.
- `state` = a random 40-char string, stored in the session.
- `code_challenge` / `code_challenge_method=S256` — PKCE. The verifier is stored in the session; the challenge is the SHA-256 hash.
- `access_type=offline` — required to receive a refresh token.
- `prompt=consent` — forces the consent screen every time, guaranteeing the `refresh_token` is included in the response.
- `include_granted_scopes=true` — Google merges any newly granted scopes with existing ones (relevant for incremental consent).

If the credential driver reports `isConfigured() === false`, this throws `OAuthException("Google OAuth credentials are not configured.")`.

Details: [[OAuth/Connect|OAuth → Connect]].

## Callback

Google redirects back to `google.auth.callback` with either `?code=…&state=…` on success or `?error=…` on failure.

`GoogleAuthController::callback()`:

1. If `?error=…` is present, redirects to `google.routes.redirect_after_error` with `google.error` flashed to the session.
2. Otherwise, requires both `code` and `state` in the query — missing either flashes an error and redirects.
3. Delegates to `OAuthManager::handleCallback( $code, $state )`.

`handleCallback()`:

1. Pulls the stored `state`, `code_verifier`, and `user_id` from the session. Any missing value throws an `OAuthException`.
2. Compares the returned `state` against the stored one with `hash_equals()` to defeat timing attacks. Mismatch = "possible CSRF attempt".
3. POSTs to the token endpoint with `grant_type=authorization_code`, `code`, `code_verifier`, `client_id`, `client_secret`, `redirect_uri`.
4. Decodes the returned `id_token`'s payload (base64url) to extract `sub` (Google user id) and `email`. **The JWT signature is not verified** — the token arrived over TLS from Google's token endpoint, so the identity claims are trustworthy for persistence purposes only (not authorization).
5. Loads or creates a `GoogleConnection` for the user, sets `access_token`, `refresh_token`, `expires_at`, `scopes`, `status = 'connected'`, and saves.

**Refresh token preservation**: Google only returns a `refresh_token` on the first consent (and on subsequent consents when `prompt=consent` is used with a new grant). Incremental-consent regrants typically omit it. `handleCallback()` only overwrites the stored refresh token when the response contains one — otherwise the existing one is preserved.

Details: [[OAuth/Callback|OAuth → Callback]].

## Reauthorize

When a new service package is installed after a user is already connected, the [[Scopes|scope registry]] starts returning scopes the connection doesn't hold. `GoogleAuthController::reauthorize()`:

1. Requires an authenticated user.
2. Builds a `ConnectionState` for the user. If they aren't connected at all, redirects to `google.auth.connect` (so onboarding, gating, and analytics that hang off connect still fire).
3. Otherwise, calls `OAuthManager::reauthorizationUrl( $userId, $state->grantedScopes )` and redirects to it.

`reauthorizationUrl()` computes the delta between what the connection holds and what the registry now requires, and asks Google for **only the missing scopes** with `include_granted_scopes=true`. Google merges the new grant with the existing one, so the user isn't reprompted for scopes they've already approved.

If nothing is missing (edge case — the caller shouldn't normally hit this route), the method falls back to requesting the full union to keep the URL valid.

Details: [[OAuth/Reauthorize|OAuth → Reauthorize]].

## Disconnect

`POST /google/auth/disconnect` calls `GoogleConnection::markDisconnected( 'Disconnected by user.' )` on the current user's connection and redirects to `redirect_after_connect` with `google.status = 'disconnected'` flashed.

**Local-only** — the flow does not hit Google's revoke endpoint. If you need remote revocation, POST to `https://oauth2.googleapis.com/revoke` from your own code with the stored refresh token before you call `markDisconnected()`.

Details: [[OAuth/Disconnect|OAuth → Disconnect]].

## Exceptions

The OAuth manager throws two exception types:

- `ArtisanPackUI\Google\Exceptions\OAuthException` — thrown by `authorizationUrl()` when credentials are missing, and by `handleCallback()` on state mismatch, missing PKCE verifier, or a failed code exchange.
- `ArtisanPackUI\Google\Exceptions\TokenRefreshException` — thrown by the [[Tokens|token manager]] when a refresh fails.

The default controller catches `OAuthException` in `callback()` and flashes the message; other callers should handle it themselves.

## Deeper topics

- [[OAuth/Connect|Connect]] — building the authorize URL, PKCE, session state, error modes.
- [[OAuth/Callback|Callback]] — code exchange, id_token decoding, refresh-token preservation.
- [[OAuth/Reauthorize|Reauthorize]] — incremental consent details and when to trigger it.
- [[OAuth/Disconnect|Disconnect]] — local vs. remote revocation, restoring a disconnected connection.

---
Continue to [[Scopes]] →
