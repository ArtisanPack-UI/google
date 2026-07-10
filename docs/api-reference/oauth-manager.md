---
title: OAuthManager
---

# `OAuthManager`

`ArtisanPackUI\Google\OAuth\OAuthManager` drives the authorization-code flow with PKCE. Fully covered in [[OAuth Flow]]; this page is the API reference.

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
}
```

## Methods

### `authorizationUrl( int|string $userId, ?array $override = null ): string`

Build the URL to send a user to Google's consent screen. Stashes `state`, `code_verifier`, and `$userId` in the session for the callback to verify.

- `$userId` — the app-user id we're connecting Google to.
- `$override` — optional explicit scope list. Defaults to `$scopes->all()`.

Throws `OAuthException` if the credential driver reports `isConfigured() === false`.

### `reauthorizationUrl( int|string $userId, array $grantedScopes ): string`

Build an incremental-consent URL that only requests scopes not already granted. Uses `include_granted_scopes=true` so Google merges the new grant with the existing one.

If `$grantedScopes` already covers everything the registry requires, falls back to requesting the full union — the URL stays valid.

### `handleCallback( string $code, string $returnedState ): GoogleConnection`

Verify the callback and persist the connection. Called from `GoogleAuthController::callback()` after Google redirects back.

Sequence:

1. Pull `state`, `code_verifier`, `user_id` from the session.
2. Compare `state` with `hash_equals()`.
3. POST to the token endpoint with the code, verifier, and client credentials.
4. Decode `id_token` payload for `sub` (Google user id) and `email`.
5. `firstOrNew` a `GoogleConnection`, update fields, save.

Preserves the existing `refresh_token` when Google doesn't return a new one.

Throws `OAuthException` on:

- Missing / mismatched `state`.
- Missing `code_verifier` or `user_id` in the session.
- Non-2xx from the token endpoint.

Returns the persisted `GoogleConnection`.

## Session keys used

| Constant | Key | Written by | Read by |
|---|---|---|---|
| `SESSION_STATE` | `google.oauth.state` | `authorizationUrl()` | `handleCallback()` |
| `SESSION_VERIFIER` | `google.oauth.verifier` | `authorizationUrl()` | `handleCallback()` |
| `SESSION_USER_ID` | `google.oauth.user_id` | `authorizationUrl()` | `handleCallback()` |

All three are `pull()`ed on callback, so replays fail cleanly.

## Related

- [[OAuth Flow]] — end-to-end walkthrough.
- [[OAuth/Connect]] — parameter-by-parameter breakdown of the authorize URL.
- [[OAuth/Callback]] — code exchange and id_token handling.
- [[OAuth/Reauthorize]] — incremental consent details.
