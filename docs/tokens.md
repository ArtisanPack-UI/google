---
title: Tokens
---

# Tokens

`ArtisanPackUI\Google\Tokens\TokenManager` is the only surface service packages need to interact with when making Google API calls. It returns a valid access token, refreshing transparently when the current one is close to expiring, and marks the connection disconnected on `invalid_grant`.

## Getting a valid access token

```php
use ArtisanPackUI\Google\Facades\Google;
use Illuminate\Support\Facades\Http;

$connection = $user->googleConnection; // or GoogleConnection::firstWhere('user_id', $user->id)
$token      = Google::tokens()->getValidAccessToken( $connection );

$response = Http::withToken( $token )
    ->get( 'https://analyticsdata.googleapis.com/v1beta/properties/123:runReport' );
```

`getValidAccessToken()` decides what to return:

1. If the connection isn't connected → throws `TokenRefreshException("Google connection is disconnected.")`.
2. If the current access token is present and not close to expiring → returns it as-is.
3. Otherwise → calls `refresh()` and returns the fresh token.

## Refresh window

`GoogleConnection::isExpired()` treats the token as expired **60 seconds before** `expires_at`:

```php
public function isExpired(): bool
{
    if ( null === $this->expires_at ) {
        return true;
    }

    return $this->expires_at->copy()->subSeconds( 60 )->isPast();
}
```

So the manager refreshes proactively — no API call ever ships with a token about to die mid-request.

Missing `expires_at` (which shouldn't happen but Google's response is technically allowed to omit `expires_in`) is treated as expired: the next call triggers a refresh.

## Forced refresh

If you know for some reason the stored token is bad, force a refresh:

```php
Google::tokens()->refresh( $connection );
```

This bypasses the expiry check and always hits the refresh endpoint. Useful in tests or when reacting to a `401` from Google that the expiry check didn't predict.

## What happens during refresh

`TokenManager::refresh()`:

1. If no refresh token is on file, calls `$connection->markDisconnected('Missing refresh token.')` and throws `TokenRefreshException("No refresh token stored for this connection.")`.
2. Otherwise, POSTs to `google.endpoints.token` (default `https://oauth2.googleapis.com/token`):
   ```
   client_id      = <from config driver>
   client_secret  = <from config driver>
   refresh_token  = <from connection>
   grant_type     = refresh_token
   ```
3. On non-success:
   - If Google returned `error=invalid_grant` → `markDisconnected('Refresh token revoked or expired.')`.
   - Either way, throws `TokenRefreshException("Google token refresh failed: <error>")`.
4. On success, updates the connection:
   - `access_token`, `token_type`, `expires_at` — always.
   - `refresh_token` — only if the response included one. (Google's refresh endpoint typically **doesn't** return a new refresh token; the existing one is preserved.)
   - `scopes` — if the response includes them (rare on refresh; more common on the initial code exchange).

Every path either returns a fresh access token or throws — there's no partial-success state.

## Failure modes

### `Google connection is disconnected.`

The connection's `status` is `'disconnected'` — someone (a prior refresh failure, a user click, an admin) marked it dead. Service packages should either surface a "Reconnect Google" prompt or defer until the user reconnects. Don't retry — a disconnected connection stays disconnected until the user re-runs `/connect`.

### `No refresh token stored for this connection.`

Rare, but possible if:

- The first connect happened without `access_type=offline` (not the case with this package — the URL builder hardcodes it).
- Google didn't return a refresh token because the user previously granted consent to the same client. This shouldn't happen either — the URL builder hardcodes `prompt=consent`, which forces the consent screen and guarantees a fresh refresh token.
- The `refresh_token` column was somehow wiped (manual DB edit).

The manager marks the connection disconnected so subsequent calls fail loudly.

### `Google token refresh failed: invalid_grant`

The refresh token has been revoked or has expired. Common causes:

- User revoked access at [myaccount.google.com/permissions](https://myaccount.google.com/permissions).
- Refresh token expired — Google's Testing-mode apps issue refresh tokens with a 7-day lifetime. Publishing the app removes that restriction.
- The user changed their Google password (in some tenant configurations).

The manager marks the connection disconnected with reason `"Refresh token revoked or expired."`.

### `Google token refresh failed: <other error>`

Any other error (`invalid_client`, `unauthorized_client`, etc.) — usually a misconfiguration. The connection is **not** marked disconnected in this case; the caller can retry after fixing config. Only `invalid_grant` triggers auto-disconnect.

## Handling exceptions in service packages

```php
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Facades\Google;

try {
    $token = Google::tokens()->getValidAccessToken( $connection );
} catch ( TokenRefreshException $e ) {
    // Re-fetch the connection; the manager may have flipped its status.
    $connection->refresh();

    if ( ! $connection->isConnected() ) {
        // Show a "reconnect" prompt to the user.
        return redirect()->route( 'settings.integrations' )
            ->with( 'error', __( 'Please reconnect Google.' ) );
    }

    // Otherwise it's likely a transient error — retry once or bubble up.
    throw $e;
}
```

## Concurrency

`TokenManager` doesn't hold locks. If two workers hit `getValidAccessToken()` for the same connection at the exact same time, both will refresh — one will win the DB write and the other will overwrite it with what it received. In practice this is harmless (both tokens are valid; Google issues them independently). If your workload is genuinely concurrent enough to care, wrap the call in `Cache::lock("google-refresh:{$connection->id}")` or serialize refreshes through a queue.

## Testing

Fake the HTTP calls with Laravel's `Http::fake()`:

```php
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\Models\GoogleConnection;
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://oauth2.googleapis.com/token' => Http::response( [
        'access_token' => 'new-token',
        'expires_in'   => 3600,
        'token_type'   => 'Bearer',
    ] ),
] );

$connection = GoogleConnection::factory()->create( [
    'access_token' => 'expired',
    'expires_at'   => now()->subMinute(),
] );

expect( Google::tokens()->getValidAccessToken( $connection ) )->toBe( 'new-token' );
```

The manager resolves its HTTP client from `Illuminate\Http\Client\Factory`, which `Http::fake()` swaps in transparently.
