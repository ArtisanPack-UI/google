---
title: BrokerClient
---

# `BrokerClient`

`ArtisanPackUI\Google\Broker\BrokerClient` runs the site-facing side of the OAuth broker contract: signed `/authorize` links, the one-time code exchange at `/token`, and refreshes at `/refresh`. Added in 1.2.0. Usage guide: [Broker Mode](Broker-Mode).

## Signature

```php
namespace ArtisanPackUI\Google\Broker;

class BrokerClient
{
    public const PROVIDER         = 'google';
    public const LINK_TTL_SECONDS = 300;

    public function __construct(
        protected BrokerCredentials $credentials,
        protected HttpFactory $http,
    );

    public static function isEnabled( ConfigRepository $config ): bool;
    public static function fromConfig( ConfigRepository $config, HttpFactory $http ): self;

    public function credentials(): BrokerCredentials;
    public function authorizationUrl( string $state, string $returnUrl, ?array $scopes = null ): string;
    public function signature( array $params ): string;
    public function exchangeCode( string $code ): TokenResponse;
    public function refresh( string $refreshToken ): TokenResponse;
    public function isTrustedRenewUrl( ?string $url ): bool;
}
```

Get one with `Google::broker( ?BrokerCredentials $credentials = null )`.

## Static methods

### `isEnabled( ConfigRepository $config ): bool`

`true` when `google.mode` is `broker`. `Google::usesBroker()` and `OAuthManager::usesBroker()` delegate here.

### `fromConfig( ConfigRepository $config, HttpFactory $http ): self`

Builds a client from `BrokerCredentials::fromConfig()`. Throws `OAuthException("Google OAuth broker credentials are not configured.")` when the URL, site ID or site secret is missing.

## Methods

### `authorizationUrl( string $state, string $returnUrl, ?array $scopes = null ): string`

`{url}/api/v1/oauth/google/authorize?site_id&state&return_url&expires[&scopes]&signature`. `expires` is now + `LINK_TTL_SECONDS`. `scopes` is space-joined and left out when `$scopes` is `null` or empty, which asks for every scope the broker allows.

### `signature( array $params ): string`

Hex HMAC-SHA256 over `"google\n" . http_build_query( ksort( $params ), RFC 3986 )`, keyed with `BrokerCredentials::signingKey()`. Any `signature` key in `$params` is ignored.

### `exchangeCode( string $code ): TokenResponse`

`POST {url}/api/v1/oauth/token` (form, bearer site secret) with `grant_type=authorization_code`. Throws `OAuthException`, with `getError()` set to the broker's `error` or `exchange_failed`, and `getRenewUrl()` set when the broker sent one.

### `refresh( string $refreshToken ): TokenResponse`

`POST {url}/api/v1/oauth/refresh` with `refresh_token` and `provider=google`. Keeps `$refreshToken` when the broker doesn't return a new one.

- `error=license_expired`, or HTTP 402 with no `error` → `LicenseExpiredException` with `getRenewUrl()`.
- Anything else → `TokenRefreshException` with `getError()` (`invalid_grant`, the broker's code, or `refresh_failed`).

### `isTrustedRenewUrl( ?string $url ): bool`

`true` only when `$url` is on the broker's host and uses HTTPS. HTTP is allowed only when the broker URL is also HTTP.

# `BrokerCredentials`

`ArtisanPackUI\Google\Broker\BrokerCredentials` is the immutable set of values a site uses to talk to the broker.

```php
final class BrokerCredentials
{
    public function __construct(
        public readonly string $url,
        public readonly string $siteId,
        public readonly string $siteSecret,
    );

    public static function isSecureUrl( string $url ): bool;
    public static function fromConfig( ConfigRepository $config ): ?self;
    public function signingKey(): string;
}
```

- **Constructor**: throws `OAuthException` when `isSecureUrl( $url )` is `false`.
- **`isSecureUrl()`**: `https://` with a host, or `http://` on `localhost`, `*.localhost`, `*.test`, `127.*` or `::1`.
- **`fromConfig()`**: reads `google.broker.url` / `site_id` / `site_secret`, runs them through the `ap.google.broker.credentials` filter, trims them (and the URL's trailing slash), and returns `null` if any is empty.
- **`signingKey()`**: `hash( 'sha256', <part of the site secret after the first "|"> )`. The whole secret is used when there's no `|`.

## Related

- [Broker Mode](Broker-Mode)
- [`TokenResponse`](API-Reference-Token-Response)
- [Exceptions](API-Reference-Exceptions)
