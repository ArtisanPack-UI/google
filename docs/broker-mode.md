---
title: Broker Mode
---

# Broker Mode

*Added in 1.2.0.*

By default the package runs in **direct** mode: your app holds its own Google OAuth client ID and secret and talks to Google itself. **Broker** mode hands that job to an OAuth broker, a separate service that holds the Google app credentials and relays the OAuth flow for many sites. The site keeps only three values: the broker URL, its `site_id`, and its site secret. **No Google client secret ever lives on the site.**

Broker mode suits distributed software, like a CMS plugin installed on many customer sites, where you can't ship a Google client secret with every install and don't want each customer to register their own Google Cloud project.

Everything above the OAuth layer stays the same: the routes, the `GoogleConnection` model, the [scope registry](Scopes), `Google::tokens()->getValidAccessToken()`, and the [connection UI](Connection-UI). Service packages don't need to know which mode the site uses.

## Enabling broker mode

```env
GOOGLE_OAUTH_MODE=broker
GOOGLE_BROKER_URL=https://broker.example.com
GOOGLE_BROKER_SITE_ID=site_123
GOOGLE_BROKER_SITE_SECRET=42|plain-secret-from-the-broker
# Optional: defaults to route('google.auth.callback')
GOOGLE_BROKER_RETURN_URL=https://your-app.test/google/auth/callback
```

Those map to `config/google.php`:

```php
'mode' => env( 'GOOGLE_OAUTH_MODE', 'direct' ),

'broker' => [
    'url'         => env( 'GOOGLE_BROKER_URL' ),
    'site_id'     => env( 'GOOGLE_BROKER_SITE_ID' ),
    'site_secret' => env( 'GOOGLE_BROKER_SITE_SECRET' ),
    'return_url'  => env( 'GOOGLE_BROKER_RETURN_URL' ),
],
```

- **`mode`**: `direct` (default) or `broker`. Any value other than `broker` means direct.
- **`broker.url`**: the broker's base URL. Trailing slashes are trimmed. It **must be HTTPS**; see [Security](#security).
- **`broker.site_id`**: this site's ID at the broker.
- **`broker.site_secret`**: this site's secret, usually in `{id}|{plain}` form. It is sent as a bearer token on every API call and used (hashed) to sign `/authorize` links.
- **`broker.return_url`**: where the broker sends the browser back. It must be on the URL the site registered with the broker. It defaults to the package's `google.auth.callback` route. If you set `google.routes.enabled = false`, you **must** set this, or building the connect URL throws `OAuthException`.

In broker mode the [credential driver](Drivers) (`client_id`, `client_secret`, `redirect_uri`) is not used for the OAuth flow at all.

You can check the mode at runtime:

```php
Google::usesBroker(); // true when google.mode === 'broker'
```

## Supplying credentials at runtime

The three broker values pass through the `ap.google.broker.credentials` [filter hook](https://github.com/ArtisanPack-UI/hooks) before use, so a host like a CMS can read them from its own settings store instead of `.env`:

```php
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.google.broker.credentials', function ( array $credentials ): array {
    return [
        'url'         => apGetSetting( 'google.broker_url' ),
        'site_id'     => apGetSetting( 'google.broker_site_id' ),
        'site_secret' => decrypt( apGetSetting( 'google.broker_site_secret' ) ),
    ];
} );
```

The filter receives `[ 'url' => …, 'site_id' => …, 'site_secret' => … ]` filled from config and must return the same shape. If any value is empty after trimming, the broker counts as **not configured**:

- `/connect` throws `OAuthException("Google OAuth broker credentials are not configured.")`. The controller doesn't catch it, so it renders as a 500, the same as missing credentials in direct mode.
- `TokenManager::refresh()` throws `TokenRefreshException` with `getError() === 'broker_not_configured'`. The connection is **not** disconnected.

`return_url` is not filtered. Set it through config.

## The flow

```
┌──────────┐            ┌──────────────┐            ┌──────────┐        ┌────────┐
│  Browser │            │  Site (this  │            │  Broker  │        │ Google │
│          │            │   package)   │            │          │        │        │
└────┬─────┘            └──────┬───────┘            └────┬─────┘        └───┬────┘
     │  GET /google/auth/connect │                         │                  │
     │──────────────────────────>│ state → session         │                  │
     │<──────────────────────────│ 302 signed /authorize   │                  │
     │───────────────────────────────────────────────────>│  consent + PKCE  │
     │                           │                         │<────────────────>│
     │<───────────────────────────────────────────────────│ 302 return_url   │
     │  GET /callback?code=…&state=…                       │   ?code&state    │
     │──────────────────────────>│ verify state            │                  │
     │                           │ POST /api/v1/oauth/token│                  │
     │                           │────────────────────────>│                  │
     │                           │<────────────────────────│ tokens           │
     │                           │ persist GoogleConnection│                  │
     │<──────────────────────────│ redirect_after_connect  │                  │
```

### Connect

`OAuthManager::authorizationUrl()` stores a random 40-character `state` and the user ID in the session. No PKCE verifier is stored, because the broker runs PKCE with Google itself. It then redirects to:

```
{broker.url}/api/v1/oauth/google/authorize
    ?site_id=…&state=…&return_url=…&expires=…&scopes=…&signature=…
```

- **`expires`**: a Unix timestamp 5 minutes out (`BrokerClient::LINK_TTL_SECONDS`). The broker accepts at most 10 minutes; the shorter window leaves room for clock skew.
- **`scopes`**: the space-separated scopes to request. On first connect this is the full [registry](Scopes) union. On `/reauthorize` it is only the missing scopes, so incremental consent works the same as in direct mode.
- **`signature`**: HMAC-SHA256, hex. The parameters (minus `signature`) are key-sorted and RFC 3986 query-encoded, prefixed with `google\n`, and keyed with `sha256( <plain part of the site secret> )`. The plain part is everything after the `|`, or the whole secret if it has no `|`.

### Callback

The broker sends the browser back to `return_url` with `?code=…&state=…`. `handleCallback()` checks `state` with `hash_equals()` just as in direct mode, then exchanges the broker's **one-time code**:

```
POST {broker.url}/api/v1/oauth/token
Authorization: Bearer {site_secret}
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&code=…
```

The broker answers with tokens in this shape:

```json
{
    "token_type": "Bearer",
    "access_token": "…",
    "refresh_token": "…",
    "expires_in": 3599,
    "scopes": [ "openid", "https://www.googleapis.com/auth/analytics.readonly" ],
    "account_email": "person@example.com",
    "account_name": "Person Name",
    "id_token": "…"
}
```

The result is saved on the user's `GoogleConnection` exactly as in direct mode. Two differences in how the row is filled:

- **Scopes**: the broker always reports scopes, so an empty `scopes` array means "unknown". The connection keeps the scopes it already holds (none, for a new connection) instead of assuming every registered scope. That way a reauthorization that is still needed doesn't get hidden.
- **Identity**: `account_email` and `account_name` from the broker win over the `id_token` claims. `google_user_id` still comes from the `id_token`'s `sub` claim.

### Refresh

`TokenManager::refresh()`, and so `getValidAccessToken()`, sends the stored refresh token to the broker:

```
POST {broker.url}/api/v1/oauth/refresh
Authorization: Bearer {site_secret}

refresh_token=…&provider=google
```

If the broker doesn't return a new refresh token, the stored one is kept.

## License expiry

A broker can refuse refreshes for a site whose license has lapsed. It answers `/refresh` with **HTTP 402** and `{"error": "license_expired", "renew_url": "…"}`.

The token manager throws `ArtisanPackUI\Google\Exceptions\LicenseExpiredException`, a subclass of `TokenRefreshException`. Unlike a revoked grant, **the connection stays connected**: the Google grant is still valid, so once the license is renewed, refreshes resume without the user reconnecting.

```php
use ArtisanPackUI\Google\Exceptions\LicenseExpiredException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;

try {
    $token = Google::tokens()->getValidAccessToken( $connection );
} catch ( LicenseExpiredException $e ) {
    // Connection is intact. Point the admin at the renewal page.
    return back()->with( 'error', $e->getMessage() )
        ->with( 'renew_url', $e->getRenewUrl() );
} catch ( TokenRefreshException $e ) {
    // See Tokens → Handling exceptions.
}
```

Catch `LicenseExpiredException` **before** `TokenRefreshException`, since it is a subclass.

The broker can also send the user back to the callback with `?error=license_expired&renew_url=…` during connect. The controller flashes the error as `google.error`, and flashes the URL as `google.renew_url` **only if it is trusted** (see below). The [Livewire connection manager](Connection-UI-Livewire) renders a "Renew license" link when `google.renew_url` is present.

## Error codes

Broker failures surface through the same exception types as direct mode, with the code available from `getError()`:

| Where | Exception | `getError()` | Connection |
|---|---|---|---|
| `/token` rejected | `OAuthException` | Broker's `error`, else `exchange_failed` | Unchanged |
| `/refresh` returns `license_expired` (or a bare 402) | `LicenseExpiredException` | `license_expired` | Stays connected |
| `/refresh` returns `invalid_grant` | `TokenRefreshException` | `invalid_grant` | **Disconnected** (`Refresh token revoked or expired.`) |
| `/refresh` other failure | `TokenRefreshException` | Broker's `error`, else `refresh_failed` | Stays connected |
| Broker not configured on refresh | `TokenRefreshException` | `broker_not_configured` | Stays connected |

A 2xx response without a non-empty `access_token` counts as a failure (`exchange_failed` / `refresh_failed`).

## Security

- **HTTPS only.** The site secret travels as a bearer token, so `BrokerCredentials` refuses a broker URL that isn't `https://` and throws `OAuthException`. Plain `http://` is accepted only for local development hosts: `localhost`, `*.localhost`, `*.test`, `127.*` and `::1`.
- **Signed, short-lived links.** `/authorize` links are HMAC-signed with a key derived from the site secret and expire after 5 minutes, so the broker can reject forged or replayed links.
- **State still protects the callback.** The site-generated `state` is checked with `hash_equals()` and pulled from the session, just like direct mode.
- **Untrusted `renew_url`.** Anyone can put a `renew_url` on the callback query string. It is flashed only when the package is in broker mode, the URL's host matches the configured broker host, and it uses HTTPS. HTTP is allowed only if the broker URL itself is HTTP, so an HTTPS broker can never be downgraded. Since 1.3.0, URLs that PHP and browsers could parse to different hosts are also dropped: anything with a backslash, whitespace, a control character or userinfo (`https://evil.test\@broker.test/` looks like the broker to PHP but opens `evil.test` in a browser). Anything else is dropped silently.

## Using the broker client directly

`Google::broker()` returns the `BrokerClient` used by the managers. You can also build one from explicit credentials:

```php
use ArtisanPackUI\Google\Broker\BrokerCredentials;

$broker = Google::broker(); // from config / the filter
$broker = Google::broker( new BrokerCredentials( 'https://broker.example.com', 'site_123', '42|secret' ) );

$url    = $broker->authorizationUrl( $state, route( 'google.auth.callback' ), [ 'openid' ] );
$tokens = $broker->exchangeCode( $code );         // TokenResponse
$tokens = $broker->refresh( $refreshToken );      // TokenResponse
```

None of these touch the session or database. See [API Reference → BrokerClient](API-Reference-Broker-Client).

## Building a broker

The other side of the contract, the broker itself, can use this same package. The [stateless client](Stateless-Client) relays the Google leg with credentials loaded at runtime, and `TokenResponse::toArray()` produces exactly the JSON shape shown above.

## Testing broker mode

Switch the mode in config and fake the broker endpoints:

```php
use Illuminate\Support\Facades\Http;

config()->set( 'google.mode', 'broker' );
config()->set( 'google.broker', [
    'url'         => 'https://broker.test',
    'site_id'     => 'site_123',
    'site_secret' => '1|secret',
] );

Http::fake( [
    'https://broker.test/api/v1/oauth/refresh' => Http::response( [
        'error'     => 'license_expired',
        'renew_url' => 'https://broker.test/renew',
    ], 402 ),
] );

expect( fn () => Google::tokens()->refresh( $connection ) )
    ->toThrow( LicenseExpiredException::class );

expect( $connection->fresh()->isConnected() )->toBeTrue();
```

## Related

- [Stateless Client](Stateless-Client): the Google-side primitives a broker relays through.
- [Configuration → OAuth mode & broker](Installation-Configuration#oauth-mode)
- [Tokens → Failure modes](Tokens#failure-modes)
- [API Reference → Exceptions](API-Reference-Exceptions)

---
Continue to [Stateless Client](Stateless-Client) →
