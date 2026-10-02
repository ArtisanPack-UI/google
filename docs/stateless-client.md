---
title: Stateless Client
---

# Stateless Client

*Added in 1.2.0.*

`OAuthManager` and `TokenManager` are **stateful**: they keep `state` and the PKCE verifier in the session and save tokens on a `GoogleConnection` row. Underneath them is a **stateless** layer, `GoogleClient`, which only talks to Google. You supply the credentials, `state`, scopes and verifier, and you get a `TokenResponse` value object back. Nothing is written to the session or the database.

Use it when the package's own persistence doesn't fit:

- **Building an OAuth broker** that relays Google OAuth for many sites, with the Google app credentials loaded from the broker's own settings. See [Broker Mode](Broker-Mode) for the site side.
- **Storing tokens somewhere other than `google_connections`**, like a per-tenant table or an external secrets store.
- **One-off scripts or jobs** that need a token exchange or refresh without a logged-in user.

The managers are thin wrappers over this layer, so both paths behave the same way with Google.

## Getting a client

```php
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\OAuth\GoogleCredentials;

// Credentials from the configured driver (config / database / cms):
$client = Google::client();

// Credentials supplied at runtime:
$client = Google::client( new GoogleCredentials(
    clientId: $settings->google_client_id,
    clientSecret: $settings->google_client_secret,
    redirectUri: 'https://broker.example.com/oauth/google/callback',
) );
```

The client always uses `google.endpoints.authorize` and `google.endpoints.token` from config, so test overrides still apply. `redirectUri` is optional. Only `authorizationUrl()` and `exchangeCode()` need it, so a client that only refreshes can leave it out.

## 1. Build the consent URL

```php
use ArtisanPackUI\Google\OAuth\GoogleClient;
use Illuminate\Support\Str;

$state    = Str::random( 40 );
$verifier = GoogleClient::generateCodeVerifier();

// Persist $state and $verifier wherever your flow keeps them.

$url = $client->authorizationUrl(
    state: $state,
    scopes: [ 'openid', 'https://www.googleapis.com/auth/analytics.readonly' ],
    parameters: [ 'login_hint' => 'person@example.com' ],
    codeVerifier: $verifier,
);
```

The URL always includes `client_id`, `redirect_uri`, `response_type=code`, `scope`, and `state`, plus these defaults:

| Parameter | Default |
|---|---|
| `access_type` | `offline` |
| `prompt` | `consent` |
| `include_granted_scopes` | `true` |

Anything in `$parameters` is merged last, so it can add parameters (`login_hint`, `hd`) or override the defaults (`prompt => 'select_account'`). PKCE (`code_challenge`, `code_challenge_method=S256`) is added **only when a verifier is passed**. Omit it to skip PKCE.

Throws `OAuthException("Google OAuth credentials are not configured.")` when the client ID is empty or no redirect URI is set.

## 2. Exchange the code

```php
$tokens = $client->exchangeCode( $code, $verifier ); // verifier optional
```

Returns a `TokenResponse`. Throws `OAuthException` when Google rejects the exchange. A 2xx response without an `access_token` is also a failure. `$e->getError()` holds Google's error code (`invalid_grant`, `redirect_uri_mismatch`, …) or `exchange_failed`.

## 3. Refresh

```php
$tokens = $client->refresh( $refreshToken );
```

Takes a raw refresh-token string, not a model. Google usually doesn't rotate refresh tokens. When it doesn't, `$tokens->refreshToken` holds the one you passed in, so you can always save it back unconditionally.

Throws `TokenRefreshException`. `getError()` is `invalid_grant` for a revoked or expired grant, Google's other error codes as reported, or `refresh_failed`. The stateless client **doesn't disconnect anything**. Deciding what a failure means is up to you.

## The `TokenResponse`

`ArtisanPackUI\Google\OAuth\TokenResponse` is an immutable value object with public readonly properties:

| Property | Type | Notes |
|---|---|---|
| `accessToken` | `string` | Always present. |
| `refreshToken` | `?string` | The previous one when the provider didn't rotate it. |
| `tokenType` | `string` | Defaults to `Bearer`. |
| `expiresIn` | `?int` | Seconds, as reported. |
| `expiresAt` | `?Carbon` | `now() + expiresIn`, or `null`. |
| `scopes` | `list<string>` | Granted scopes. **Empty when the provider didn't report them.** |
| `idToken` | `?string` | Raw, unverified JWT. |
| `accountId` | `?string` | The `sub` claim. |
| `accountEmail` | `?string` | Broker's `account_email`, else the `email` claim. |
| `accountName` | `?string` | Broker's `account_name`, else the `name` claim. |

The `id_token` is decoded but its signature is **not verified**. The claims are fine for labeling a connection, not for authorization. See the [FAQ](FAQ#why-doesnt-the-callback-verify-the-id_token-signature).

`toArray()` renders the broker wire shape, so a broker can return it as JSON as-is:

```php
return response()->json( $tokens->toArray() );
// { token_type, access_token, refresh_token, expires_in, scopes, account_email, account_name, id_token }
```

## Example: a minimal broker relay

```php
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\OAuth\GoogleClient;
use ArtisanPackUI\Google\OAuth\GoogleCredentials;

$client = Google::client( new GoogleCredentials(
    $broker->clientId(),
    $broker->clientSecret(),
    route( 'broker.google.callback' ),
) );

// /authorize: after validating the site's signed link.
$verifier = GoogleClient::generateCodeVerifier();
$pending  = $broker->rememberPending( $site, $request->state, $request->return_url, $verifier );

return redirect()->away( $client->authorizationUrl( $pending->id, explode( ' ', $request->scopes ), [], $verifier ) );

// Google callback: exchange and hand the site a one-time code.
$tokens = $client->exchangeCode( $request->code, $pending->verifier );
$code   = $broker->issueOneTimeCode( $pending, $tokens );

return redirect()->away( $pending->return_url . '?' . http_build_query( [ 'code' => $code, 'state' => $pending->siteState ] ) );

// POST /token: redeem the one-time code.
return response()->json( $broker->redeem( $request->code )->toArray() );

// POST /refresh
try {
    return response()->json( $client->refresh( $request->refresh_token )->toArray() );
} catch ( TokenRefreshException $e ) {
    return response()->json( [ 'error' => $e->getError() ], 400 );
}
```

`$broker` here stands in for your own storage. The package provides only the Google leg and the response shape.

## PKCE helpers

```php
GoogleClient::generateCodeVerifier();       // 86-char base64url random string
GoogleClient::codeChallenge( $verifier );   // S256 challenge, base64url, no padding
```

## Testing

The client resolves its HTTP client from the container, so `Http::fake()` works:

```php
Http::fake( [
    'https://oauth2.googleapis.com/token' => Http::response( [
        'access_token' => 'fresh',
        'expires_in'   => 3600,
        'scope'        => 'openid email',
    ] ),
] );

$tokens = Google::client( new GoogleCredentials( 'id', 'secret' ) )->refresh( 'stored-refresh' );

expect( $tokens->accessToken )->toBe( 'fresh' )
    ->and( $tokens->refreshToken )->toBe( 'stored-refresh' )
    ->and( $tokens->scopes )->toBe( [ 'openid', 'email' ] );
```

## Related

- [Broker Mode](Broker-Mode): the site side of a broker.
- [API Reference → GoogleClient](API-Reference-Google-Client)
- [API Reference → TokenResponse](API-Reference-Token-Response)

---
Continue to [Scopes](Scopes) →
