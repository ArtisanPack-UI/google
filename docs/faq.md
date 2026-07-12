---
title: FAQ
---

# FAQ

## Setup

### Do I need a Google Workspace account?

No. External OAuth clients work with any Google account. Workspace accounts are only relevant if you set the consent screen's user type to **Internal**, which restricts the app to users on your Workspace domain.

### Can I use this package without Livewire?

Yes. Livewire is a peer dependency for the `<livewire:google-connection-manager />` component only. The base package boots without Livewire — the OAuth flow, token manager, scope registry, and JSON status endpoint all work independently. Use the [React](Connection-UI-React) or [Vue](Connection-UI-Vue) components, or build your own on the [status endpoint](Connection-UI-Custom).

### Can I use this package without the CMS framework?

Yes. The CMS framework is only required if you set `GOOGLE_CONFIG_DRIVER=cms`. The default `config` driver and the `database` driver work without it. If you pick `cms` without the framework installed, the driver's setting keys are never registered — reads return `null` and `isConfigured()` returns `false`, so you get a clean "not configured" state instead of a hard boot error.

## Credentials

### Where should I put my client secret?

Depends on your setup:

- **Single-tenant**, credentials rotate with deploys → `config` driver, `.env`.
- **Multi-tenant** or credentials managed via an admin UI → `database` driver. Secret is encrypted with `APP_KEY`.
- **CMS-driven** projects → `cms` driver. Same encryption story.

See [Credential Drivers](Drivers) for the full comparison.

### What happens when I rotate `APP_KEY`?

Any encrypted values become unreadable until you re-encrypt them. Two places matter:

1. **`client_secret` in `google_configurations` / CMS Settings** — the `database` and `cms` drivers log a warning and treat the row as unconfigured. `isConfigured()` returns `false`; attempts to build an authorize URL throw `OAuthException("Google OAuth credentials are not configured.")`.
2. **`access_token` and `refresh_token` in `google_connections`** — Eloquent's `encrypted` cast throws `DecryptException` on read.

Plan a re-encryption pass alongside key rotations. If you can't, at minimum re-save the client secret via `Google::config()->save()` — every connection will then need to reconnect (their token columns are dead) but the credential driver will start reporting configured again.

### Can I use a different Google client per tenant?

Yes, with the `database` driver. Rebind it per-tenant by resolving with a tenant-scoped database connection:

```php
$this->app->singleton( DatabaseDriver::class, function ( Application $app ): DatabaseDriver {
    return new DatabaseDriver( $app[ 'db' ]->connection( 'tenant' ), $app[ 'encrypter' ] );
} );
```

Every tenant then gets its own `google_configurations` row on its own database connection.

## OAuth flow

### Why does the user get consent-screened every connect?

The package hardcodes `prompt=consent` on the authorize URL. This forces the consent screen every time, which guarantees Google returns a fresh `refresh_token`. Without it, repeat connects from an already-consented account get access tokens with no refresh token attached — and the connection would silently break on next expiry.

Downside: users see the consent screen every time they hit `/connect`. Since most apps only connect once per user, this is usually invisible. If your flow legitimately reconnects often, consider [`/reauthorize`](Oauth-Reauthorize) instead — it only prompts for scopes the user hasn't already granted.

### Why doesn't the callback verify the id_token signature?

The id_token arrives over TLS from Google's token endpoint on a connection we initiated. We use the `sub` and `email` claims for identity **persistence only** — labeling the row so we can display "Connected as {email}" in the UI. We do **not** use them for authorization decisions, so signature validation would be pointless overhead.

If your use case actually authorizes off the id_token (e.g., logging the user into your app via Google Sign-In), verify the signature externally with `firebase/php-jwt` before trusting the claims.

### Why don't disconnects revoke the token with Google?

Local-only disconnect is faster, doesn't require a network round-trip, and avoids failure modes where Google is unreachable. The refresh token stays valid on Google's side until:

- The user manually revokes at [myaccount.google.com/permissions](https://myaccount.google.com/permissions).
- The token expires (Testing-mode apps: 7 days).
- Your app hits Google's revoke endpoint.

If you need remote revocation, POST to `https://oauth2.googleapis.com/revoke` before calling `markDisconnected()`. See [OAuth/Disconnect#Local-only revocation](Oauth-Disconnect#local-only-revocation).

### The refresh flow throws `invalid_grant`. What happened?

Three usual causes:

1. **User revoked access** on Google's side.
2. **Refresh token expired** — for apps in "Testing" mode, refresh tokens die after 7 days. Publishing the app removes that limit.
3. **User changed their Google password** in some tenant configurations.

The token manager marks the connection disconnected with reason `"Refresh token revoked or expired."` and throws `TokenRefreshException`. The user needs to re-run `/connect`.

## Scopes

### How do I add a new scope?

For service packages: hook `ap.google.scopes` in `boot()`:

```php
Filter::add( 'ap.google.scopes', fn ( array $scopes ) => [ ...$scopes, 'https://www.googleapis.com/auth/new-scope' ] );
```

For app code: `Google::scopes()->register( 'https://www.googleapis.com/auth/new-scope' )`.

Then: **add the scope in Google Cloud Console** under **OAuth consent screen → Scopes**. Google rejects authorize requests that ask for scopes not listed there with `invalid_scope`.

Users who were already connected will need to reauthorize — the [connection UI](Connection-UI) surfaces this automatically when `needsReauthorize === true`.

### Do users get re-prompted for every scope on incremental consent?

No. The `/reauthorize` route only asks for the delta between what the connection holds and what the registry now requires. `include_granted_scopes=true` tells Google to merge the new grant with the existing one, so the user only approves the new scopes.

### Can I remove a scope?

You can stop registering it, but the connection's `grantedScopes` will still include it until the user manually revokes at [myaccount.google.com/permissions](https://myaccount.google.com/permissions) or reconnects. Nothing in the package unregisters granted scopes.

## Runtime

### The `/connect` route 500s with "credentials are not configured"

Your credential driver reports `isConfigured() === false`. Check:

- `config('google.driver')` — is it the driver you expect?
- For `config` driver: are the three env vars set?
- For `database` / `cms` driver: did you `Google::config()->save()` credentials? Are they still readable (i.e., did `APP_KEY` rotate)?

### The `/callback` route flashes "OAuth state mismatch"

The session lost the `google.oauth.state` value between `/connect` and `/callback`. Check:

- `SESSION_DRIVER` — cookie, file, database, redis all work; the null driver doesn't.
- `SESSION_SAME_SITE` — must be `lax` (default) or `none` for OAuth redirects to include the session cookie.
- `SESSION_SECURE_COOKIE` — must be `false` for `http://` local dev.
- Cookie domain / subdomain mismatches when the redirect URI is on a different host.

### The connection UI doesn't refresh after connect

The connect flow does a full-page redirect via `redirect()->away()`, so the browser navigates and the whole page re-renders on return. If your UI is inside an SPA that didn't tear down, refetch `/google/auth/status` on the redirect landing page — or add a `redirect_after_connect` route handler that dispatches a `google-connection-updated` event that the Livewire component listens for.

### Can I attach connections to something other than a `User`?

Yes. Set `GOOGLE_USER_MODEL` (or `config('google.user_model')`) to a different Eloquent model. The `BelongsTo` relation on `GoogleConnection::user()` will target it. The OAuth flow uses `Auth::user()->getAuthIdentifier()`, so whichever guarded model you use for auth must expose that method — which any Eloquent auth model does.
