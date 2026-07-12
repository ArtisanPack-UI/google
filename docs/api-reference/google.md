---
title: Google
---

# `Google`

`ArtisanPackUI\Google\Google` is the aggregator class the facade points at. It holds references to the four manager singletons and exposes them via accessor methods.

## Constructor

```php
public function __construct(
    protected ConfigurationRepository $config,
    protected ScopeRegistry $scopes,
    protected TokenManager $tokens,
    protected OAuthManager $oauth,
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
