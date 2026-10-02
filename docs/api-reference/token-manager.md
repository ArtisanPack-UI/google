---
title: TokenManager
---

# `TokenManager`

`ArtisanPackUI\Google\Tokens\TokenManager` returns valid access tokens, refreshing transparently when the current one is close to expiring. Fully covered in [Tokens](Tokens); this page is the API reference.

## Signature

```php
namespace ArtisanPackUI\Google\Tokens;

class TokenManager
{
    public function __construct(
        protected ConfigurationRepository $config,
        protected ConfigRepository $laravelConfig,
        protected HttpFactory $http,
    );

    public function getValidAccessToken( GoogleConnection $connection ): string;
    public function refresh( GoogleConnection $connection ): string;
}
```

## Methods

### `getValidAccessToken( GoogleConnection $connection ): string`

Return a valid access token. Refreshes if the current one is expired or missing.

Behavior:

1. If `! $connection->isConnected()` → throws `TokenRefreshException`.
2. If `! $connection->isExpired() && ! empty( $connection->access_token )` → returns the stored token.
3. Otherwise → delegates to `refresh()` and returns the fresh token.

Throws `TokenRefreshException` on refresh failure.

### `refresh( GoogleConnection $connection ): string`

Force a refresh regardless of expiry. In direct mode it calls `GoogleClient::refresh()` against `google.endpoints.token`; when `google.mode` is `broker` it calls `BrokerClient::refresh()` against the broker's `/oauth/refresh` (see [Broker Mode](Broker-Mode#refresh)).

Behavior:

- Missing refresh token → `markDisconnected('Missing refresh token.')` and throw.
- Failure — the thrown exception's `getError()` decides:
  - `invalid_grant` → `markDisconnected('Refresh token revoked or expired.')` and rethrow.
  - `license_expired` (broker mode) → rethrow `LicenseExpiredException` without changing the connection.
  - `broker_not_configured` (broker mode) → throw without changing the connection.
  - Anything else → rethrow without changing the connection status.
- Success → update `access_token`, `token_type`, `refresh_token` (the new one, or the existing one when not rotated), and (if present) `expires_at` and `scopes`. Save and return the new access token.

Throws `LicenseExpiredException` (since 1.2.0, a `TokenRefreshException` subclass) or `TokenRefreshException`.

## Refresh window

The `isExpired()` check on the connection treats tokens as expired **60 seconds before** `expires_at`, so refresh happens proactively.

## HTTP client

The manager resolves `Illuminate\Http\Client\Factory` from the container. `Http::fake()` in tests transparently intercepts calls — see [Tokens → Testing](Tokens#testing).

## Related

- [Tokens](Tokens) — usage, failure modes, retry patterns.
- [`TokenRefreshException` / `LicenseExpiredException`](API-Reference-Exceptions).
