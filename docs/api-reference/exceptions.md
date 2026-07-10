---
title: Exceptions
---

# Exceptions

Two exception types live in `ArtisanPackUI\Google\Exceptions`. Both extend `\Exception`.

## `OAuthException`

`ArtisanPackUI\Google\Exceptions\OAuthException` — thrown during the [[OAuth Flow|authorization-code flow]].

### Thrown by

`OAuthManager::buildAuthorizationUrl()`:

- `"Google OAuth credentials are not configured."` — credential driver reports `isConfigured() === false`.

`OAuthManager::handleCallback()`:

- `"OAuth state mismatch; possible CSRF attempt."` — the returned `state` doesn't match the session-stashed one (or the session lost the value).
- `"PKCE code verifier missing from session."` — the session lost `google.oauth.verifier`.
- `"OAuth session missing user context."` — the session lost `google.oauth.user_id`.
- `"Google code exchange failed: <error>"` — token-endpoint returned non-2xx. Common `<error>` values: `invalid_grant`, `redirect_uri_mismatch`, `invalid_client`.

### Default handling

`GoogleAuthController::callback()` catches `OAuthException` and redirects to `redirect_after_error` with the message flashed as `google.error`. Custom controllers should catch it themselves.

### Example

```php
try {
    $connection = Google::oauth()->handleCallback( $code, $state );
} catch ( OAuthException $e ) {
    return redirect( '/settings' )->with( 'error', $e->getMessage() );
}
```

## `TokenRefreshException`

`ArtisanPackUI\Google\Exceptions\TokenRefreshException` — thrown by the [[Tokens|token manager]].

### Thrown by

`TokenManager::getValidAccessToken()`:

- `"Google connection is disconnected."` — the connection's status is `disconnected`. Retrying won't help; the user must reconnect.

`TokenManager::refresh()`:

- `"No refresh token stored for this connection."` — refresh token column is empty. The manager also flips the connection to disconnected with reason `"Missing refresh token."`.
- `"Google token refresh failed: <error>"` — refresh endpoint returned non-2xx. If `<error> === 'invalid_grant'`, the manager also flips the connection to disconnected with reason `"Refresh token revoked or expired."`.

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

Full failure-mode reference: [[Tokens#failure-modes]].

## Custom-driver exceptions

`ConfigDriver::save()` throws `RuntimeException("The config driver is read-only. Switch to the database driver to persist credentials.")` — not a package-specific class, since it's really just "you called an unsupported operation".

Custom drivers should follow the same pattern — throw `RuntimeException` from methods they don't support.
