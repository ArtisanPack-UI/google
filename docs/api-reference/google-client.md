---
title: GoogleClient
---

# `GoogleClient`

`ArtisanPackUI\Google\OAuth\GoogleClient` is the stateless Google OAuth client added in 1.2.0. It never touches the session or the database. `OAuthManager` and `TokenManager` wrap it to add session handling and persistence. Usage guide: [Stateless Client](Stateless-Client).

## Signature

```php
namespace ArtisanPackUI\Google\OAuth;

class GoogleClient
{
    public const DEFAULT_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const DEFAULT_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';

    public function __construct(
        protected GoogleCredentials $credentials,
        protected HttpFactory $http,
        protected string $authorizeEndpoint = self::DEFAULT_AUTHORIZE_ENDPOINT,
        protected string $tokenEndpoint = self::DEFAULT_TOKEN_ENDPOINT,
    );

    public static function make( GoogleCredentials|ConfigurationRepository $credentials, HttpFactory $http, ConfigRepository $config ): self;
    public static function generateCodeVerifier(): string;
    public static function codeChallenge( string $verifier ): string;

    public function credentials(): GoogleCredentials;
    public function authorizationUrl( string $state, array $scopes, array $parameters = [], ?string $codeVerifier = null ): string;
    public function exchangeCode( string $code, ?string $codeVerifier = null ): TokenResponse;
    public function refresh( string $refreshToken ): TokenResponse;
}
```

Usually you get one from `Google::client( ?GoogleCredentials $credentials = null )`, which calls `make()` with the explicit credentials or the bound credential driver.

## Static methods

### `make( GoogleCredentials|ConfigurationRepository $credentials, HttpFactory $http, ConfigRepository $config ): self`

Builds a client. A `ConfigurationRepository` is turned into credentials with `GoogleCredentials::fromRepository()`. The endpoints come from `google.endpoints.authorize` and `google.endpoints.token`.

### `generateCodeVerifier(): string`

A random PKCE code verifier: 64 random bytes, base64url-encoded without padding (86 characters).

### `codeChallenge( string $verifier ): string`

The S256 challenge for a verifier: base64url of the raw SHA-256 hash, without padding.

## Methods

### `authorizationUrl( string $state, array $scopes, array $parameters = [], ?string $codeVerifier = null ): string`

Builds the Google consent URL. Defaults: `response_type=code`, `access_type=offline`, `prompt=consent`, `include_granted_scopes=true`. `$parameters` is merged last, so it can add parameters or override these defaults. `code_challenge` / `code_challenge_method=S256` are added only when `$codeVerifier` is given.

Throws `OAuthException` when the client ID is empty or the redirect URI is `null`.

### `exchangeCode( string $code, ?string $codeVerifier = null ): TokenResponse`

POSTs `grant_type=authorization_code` to the token endpoint with the client credentials, redirect URI and, if given, `code_verifier`.

Throws `OAuthException("Google code exchange failed: <error>")` on a non-2xx response or a 2xx response without `access_token`. `getError()` returns Google's `error`, or `exchange_failed`.

### `refresh( string $refreshToken ): TokenResponse`

POSTs `grant_type=refresh_token`. When Google doesn't return a new refresh token, the returned `TokenResponse` carries `$refreshToken`.

Throws `TokenRefreshException("Google token refresh failed: <error>")`. `getError()` returns Google's `error` (for example `invalid_grant`), or `refresh_failed`.

### `credentials(): GoogleCredentials`

The credentials the client was built with.

# `GoogleCredentials`

`ArtisanPackUI\Google\OAuth\GoogleCredentials` is an immutable value object holding a Google OAuth app's credentials.

```php
final class GoogleCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $redirectUri = null,
    );

    public static function fromRepository( ConfigurationRepository $repository ): self;
}
```

- `redirectUri` is only needed for `authorizationUrl()` and `exchangeCode()`.
- `fromRepository()` reads the active [credential driver](API-Reference-Configuration-Repository). An empty redirect URI becomes `null`.

## Related

- [Stateless Client](Stateless-Client)
- [`TokenResponse`](API-Reference-Token-Response)
- [Exceptions](API-Reference-Exceptions)
