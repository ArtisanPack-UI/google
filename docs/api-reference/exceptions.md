---
title: Exceptions
---

# Exceptions

Three exception types live in `ArtisanPackUI\Google\Exceptions`. `OAuthException` and `TokenRefreshException` extend `\RuntimeException`; `LicenseExpiredException` extends `TokenRefreshException`.

## Error codes and renew URLs

*Since 1.2.0.* All three use the `Concerns\CarriesOAuthError` trait, which adds a machine-readable OAuth error code and an optional license renewal URL alongside the translated message:

```php
public function __construct(
    string $message = '',
    ?string $error = null,      // e.g. 'invalid_grant', 'license_expired'
    ?string $renewUrl = null,   // broker license renewal URL
    ?Throwable $previous = null,
);

public function getError(): ?string;
public function getRenewUrl(): ?string;
```

Branch on `getError()` rather than parsing the message — messages are translated.

## `OAuthException`

`ArtisanPackUI\Google\Exceptions\OAuthException` — thrown during the [authorization-code flow](Oauth).

### Thrown by

`OAuthManager::buildAuthorizationUrl()` / `GoogleClient::authorizationUrl()`:

- `"Google OAuth credentials are not configured."` — credential driver reports `isConfigured() === false`, or the client ID / redirect URI is missing.

Broker mode (`BrokerClient` / `BrokerCredentials`):

- `"Google OAuth broker credentials are not configured."` — the broker URL, site ID, or site secret is missing.
- `"The Google OAuth broker URL must use HTTPS; plain HTTP is only allowed for local development hosts."` — see [Broker Mode → Security](Broker-Mode#security).
- `"Set google.broker.return_url when the package routes are disabled."`

`OAuthManager::handleCallback()`:

- `"OAuth state mismatch; possible CSRF attempt."` — the returned `state` doesn't match the session-stashed one (or the session lost the value).
- `"PKCE code verifier missing from session."` — the session lost `google.oauth.verifier` (direct mode only).
- `"OAuth session missing user context."` — the session lost `google.oauth.user_id`.
- `"Google code exchange failed: <error>"` — Google (or the broker's `/token`) rejected the exchange, or returned no `access_token`. Common `<error>` values: `invalid_grant`, `redirect_uri_mismatch`, `invalid_client`, `exchange_failed`. Available as `getError()`.

### Default handling

`GoogleAuthController::callback()` catches `OAuthException` and redirects to `redirect_after_error` with the message flashed as `google.error` (and, in broker mode, a trusted `getRenewUrl()` flashed as `google.renew_url`). Custom controllers should catch it themselves.

### Example

```php
try {
    $connection = Google::oauth()->handleCallback( $code, $state );
} catch ( OAuthException $e ) {
    return redirect( '/settings' )->with( 'error', $e->getMessage() );
}
```

## `TokenRefreshException`

`ArtisanPackUI\Google\Exceptions\TokenRefreshException` — thrown by the [token manager](Tokens).

### Thrown by

`TokenManager::getValidAccessToken()`:

- `"Google connection is disconnected."` — the connection's status is `disconnected`. Retrying won't help; the user must reconnect.

`TokenManager::refresh()`:

- `"No refresh token stored for this connection."` — refresh token column is empty. The manager also flips the connection to disconnected with reason `"Missing refresh token."`.
- `"Google token refresh failed: <error>"` — the refresh endpoint (Google or broker) rejected the refresh. `getError()` returns `<error>`. If it is `invalid_grant`, the manager also flips the connection to disconnected with reason `"Refresh token revoked or expired."`.
- `"Google OAuth broker credentials are not configured."` — broker mode with no broker credentials. `getError()` is `broker_not_configured`; the connection is left alone.

### Default handling

Not caught anywhere in the package — service packages should handle it:

```php
try {
    $token = Google::tokens()->getValidAccessToken( $connection );
} catch ( TokenRefreshException $e ) {
    $connection->refresh();

    if ( ! $connection->isConnected() ) {
        // Prompt user to reconnect.
        return redirect()->route( 'settings.integrations' )
            ->with( 'error', __( 'Please reconnect Google.' ) );
    }

    // Otherwise it's likely transient — retry once or bubble.
    throw $e;
}
```

Full failure-mode reference: [Tokens#failure-modes](Tokens#failure-modes).

## `LicenseExpiredException`

*Since 1.2.0.* `ArtisanPackUI\Google\Exceptions\LicenseExpiredException extends TokenRefreshException` — thrown in [broker mode](Broker-Mode#license-expiry) when the broker refuses a refresh because the site's license has lapsed (HTTP 402 / `license_expired`).

- `getError()` → `'license_expired'`.
- `getRenewUrl()` → the broker's renewal page, when it sent one.

The connection is **not** disconnected — the Google grant is still valid and refreshes resume once the license is renewed. Because it is a subclass, catch it before `TokenRefreshException`:

```php
try {
    $token = Google::tokens()->getValidAccessToken( $connection );
} catch ( LicenseExpiredException $e ) {
    return back()->with( 'renew_url', $e->getRenewUrl() );
} catch ( TokenRefreshException $e ) {
    // ...
}
```

## Custom-driver exceptions

`ConfigDriver::save()` throws `RuntimeException("The config driver is read-only. Switch to the database driver to persist credentials.")` — not a package-specific class, since it's really just "you called an unsupported operation".

Custom drivers should follow the same pattern — throw `RuntimeException` from methods they don't support.
