---
title: Google
---

# `Google`

`ArtisanPackUI\Google\Google` is the aggregator class the facade points at. It holds references to the four manager singletons and exposes them via accessor methods, plus factories for the stateless [Google](Stateless-Client) and [broker](Broker-Mode) clients.

## Constructor

```php
public function __construct(
    protected ConfigurationRepository $config,
    protected ScopeRegistry $scopes,
    protected TokenManager $tokens,
    protected OAuthManager $oauth,
    protected HttpFactory $http,              // since 1.2.0
    protected ConfigRepository $laravelConfig, // since 1.2.0
)
```

Instantiated once by the service provider; you don't build these directly.

## Methods

### `config(): ConfigurationRepository`

The active credential driver. See [API Reference/Configuration Repository](API-Reference-Configuration-Repository).

```php
Google::config()->getClientId();
Google::config()->save( [ ... ] );
```

### `scopes(): ScopeRegistry`

The [scope registry](Scopes).

```php
Google::scopes()->register( 'https://www.googleapis.com/auth/analytics.readonly' );
Google::scopes()->all();
```

### `tokens(): TokenManager`

The [token manager](Tokens).

```php
$token = Google::tokens()->getValidAccessToken( $connection );
```

### `oauth(): OAuthManager`

The [OAuth manager](API-Reference-Oauth-Manager).

```php
$url = Google::oauth()->authorizationUrl( $userId );
```

### `client( ?GoogleCredentials $credentials = null ): GoogleClient`

*Since 1.2.0.* A stateless Google OAuth client. With no arguments it uses the active credential driver; pass explicit `GoogleCredentials` to relay for another app, as an OAuth broker does. The client never touches the session or the database. See [Stateless Client](Stateless-Client).

```php
$tokens = Google::client()->refresh( $refreshToken );

$tokens = Google::client( new GoogleCredentials( $clientId, $clientSecret, $redirectUri ) )
    ->exchangeCode( $code, $verifier );
```

### `broker( ?BrokerCredentials $credentials = null ): BrokerClient`

*Since 1.2.0.* A client for the OAuth broker, from explicit credentials or from `google.broker` config (via the `ap.google.broker.credentials` filter). Throws `OAuthException` when no credentials are passed and none are configured. See [Broker Mode](Broker-Mode).

```php
$tokens = Google::broker()->refresh( $refreshToken );
```

### `usesBroker(): bool`

*Since 1.2.0.* Whether the package is in broker client mode (`google.mode` = `broker`).

```php
if ( Google::usesBroker() ) {
    // No Google client secret on this site.
}
```

## The facade

`ArtisanPackUI\Google\Facades\Google` extends `Illuminate\Support\Facades\Facade` and returns `'google'` from `getFacadeAccessor()`. That resolves to a singleton binding of `Google`. Every facade call goes through this instance.

## The helper

```php
function google(): Google
{
    return app( 'google' );
}
```

Same singleton as the facade. Use whichever style your codebase prefers.
