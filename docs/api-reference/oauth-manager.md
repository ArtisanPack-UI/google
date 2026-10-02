---
title: OAuthManager
---

# `OAuthManager`

`ArtisanPackUI\Google\OAuth\OAuthManager` drives the authorization-code flow with PKCE. Fully covered in [OAuth Flow](Oauth); this page is the API reference.

Since 1.2.0 the manager is a stateful wrapper: it handles the session and persistence, and delegates the actual Google calls to the stateless [`GoogleClient`](API-Reference-Google-Client) — or, when `google.mode` is `broker`, to [`BrokerClient`](API-Reference-Broker-Client).

## Signature

```php
namespace ArtisanPackUI\Google\OAuth;

class OAuthManager
{
    public function __construct(
        protected ConfigurationRepository $config,
        protected ConfigRepository $laravelConfig,
        protected Session $session,
        protected HttpFactory $http,
        protected ScopeRegistry $scopes,
    );

    public function authorizationUrl( int|string $userId, ?array $override = null ): string;
    public function reauthorizationUrl( int|string $userId, array $grantedScopes ): string;
    public function handleCallback( string $code, string $returnedState ): GoogleConnection;

    // Since 1.2.0
    public function usesBroker(): bool;
    public function client(): GoogleClient;
    public function brokerClient(): BrokerClient;
    public function isTrustedRenewUrl( ?string $url ): bool;
}
```

## Methods

### `authorizationUrl( int|string $userId, ?array $override = null ): string`

Build the URL to send a user to Google's consent screen. Stashes `state`, `code_verifier`, and `$userId` in the session for the callback to verify.

- `$userId` — the app-user id we're connecting Google to.
- `$override` — optional explicit scope list. Defaults to `$scopes->all()`.

Throws `OAuthException` if the credential driver reports `isConfigured() === false`.

**Broker mode:** returns the signed broker `/authorize` URL instead. Only `state` and `$userId` are stashed — the verifier key is cleared, since the broker runs PKCE with Google. Throws `OAuthException` when the broker isn't configured, or when `google.broker.return_url` is empty and the package routes are disabled. See [Broker Mode → Connect](Broker-Mode#connect).

### `reauthorizationUrl( int|string $userId, array $grantedScopes ): string`

Build an incremental-consent URL that only requests scopes not already granted. Uses `include_granted_scopes=true` so Google merges the new grant with the existing one.

If `$grantedScopes` already covers everything the registry requires, falls back to requesting the full union — the URL stays valid.

### `handleCallback( string $code, string $returnedState ): GoogleConnection`

Verify the callback and persist the connection. Called from `GoogleAuthController::callback()` after Google redirects back.

Sequence:

1. Pull `state`, `code_verifier`, `user_id` from the session.
2. Compare `state` with `hash_equals()`.
3. Exchange the code: `GoogleClient::exchangeCode( $code, $verifier )` in direct mode, `BrokerClient::exchangeCode( $code )` in broker mode. Both return a [`TokenResponse`](API-Reference-Token-Response) with the `id_token` claims (`sub`, `email`) already decoded.
4. `firstOrNew` a `GoogleConnection`, update fields, save.

Preserves the existing `refresh_token` when the response doesn't include a new one.

Scopes: reported scopes always win. When none are reported, direct mode falls back to the full registry (Google omits `scope` only when it granted what was asked); broker mode keeps the connection's existing scopes.

Throws `OAuthException` on:

- Missing / mismatched `state`.
- Missing `code_verifier` (direct mode only) or `user_id` in the session.
- A rejected code exchange — `getError()` carries the error code.

Returns the persisted `GoogleConnection`.

### `usesBroker(): bool`

*Since 1.2.0.* `true` when `google.mode` is `broker`.

### `client(): GoogleClient`

*Since 1.2.0.* The stateless Google client built from the active credential driver and the configured endpoints.

### `brokerClient(): BrokerClient`

*Since 1.2.0.* The broker client built from `google.broker` config. Throws `OAuthException` when the broker isn't configured.

### `isTrustedRenewUrl( ?string $url ): bool`

*Since 1.2.0.* Whether a license `renew_url` from the callback query string is safe to show the user. Always `false` outside broker mode (or when the broker is misconfigured); otherwise delegates to `BrokerClient::isTrustedRenewUrl()`.

## Session keys used

| Constant | Key | Written by | Read by |
|---|---|---|---|
| `SESSION_STATE` | `google.oauth.state` | `authorizationUrl()` | `handleCallback()` |
| `SESSION_VERIFIER` | `google.oauth.verifier` | `authorizationUrl()` (direct mode; cleared in broker mode) | `handleCallback()` |
| `SESSION_USER_ID` | `google.oauth.user_id` | `authorizationUrl()` | `handleCallback()` |

All three are `pull()`ed on callback, so replays fail cleanly.

## Related

- [OAuth Flow](Oauth) — end-to-end walkthrough.
- [OAuth/Connect](Oauth-Connect) — parameter-by-parameter breakdown of the authorize URL.
- [OAuth/Callback](Oauth-Callback) — code exchange and id_token handling.
- [OAuth/Reauthorize](Oauth-Reauthorize) — incremental consent details.
- [Broker Mode](Broker-Mode) — the broker flow.
- [Stateless Client](Stateless-Client) — the primitives this manager wraps.
