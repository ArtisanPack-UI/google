---
title: Callback
---

# Callback

`GoogleAuthController::callback()` handles Google's redirect back to `/google/auth/callback`. It sits between Google's response and the persisted `GoogleConnection` row.

## The controller

```php
public function callback( Request $request ): RedirectResponse
{
    if ( $error = $request->query( 'error' ) ) {
        return $this->redirectAfterError()->with( 'google.error', (string) $error );
    }

    $code  = (string) $request->query( 'code', '' );
    $state = (string) $request->query( 'state', '' );

    if ( '' === $code || '' === $state ) {
        return $this->redirectAfterError()->with(
            'google.error',
            __( 'Google callback is missing required code or state parameter.' ),
        );
    }

    try {
        $this->oauth->handleCallback( $code, $state );
    } catch ( OAuthException $e ) {
        return $this->redirectAfterError()->with( 'google.error', $e->getMessage() );
    }

    return $this->redirectAfterConnect()->with( 'google.status', 'connected' );
}
```

Route: `GET /google/auth/callback` → `google.auth.callback`.

## Error branches

The callback URL always ends up hitting one of three outcomes:

1. **`?error=…`** — the user denied consent or Google rejected something. Redirect to `redirect_after_error` with `google.error = <error code>`.
2. **Missing `code` or `state`** — malformed callback. Redirect to `redirect_after_error` with a translated message.
3. **`OAuthException` from `handleCallback()`** — state mismatch, missing PKCE verifier, or token exchange failed. Redirect to `redirect_after_error` with the exception message.

On success: redirect to `redirect_after_connect` with `google.status = 'connected'` flashed.

You can read the flashed values in your redirect target:

```blade
@if( session( 'google.error' ) )
    <div class="alert alert-error">{{ session( 'google.error' ) }}</div>
@elseif( session( 'google.status' ) === 'connected' )
    <div class="alert alert-success">Google account connected.</div>
@endif
```

## `handleCallback()` internals

`OAuthManager::handleCallback( $code, $state )` does the actual work:

### 1. Pull + validate session state

```php
$storedState    = $this->session->pull( self::SESSION_STATE );
$verifier       = $this->session->pull( self::SESSION_VERIFIER );
$userId         = $this->session->pull( self::SESSION_USER_ID );
```

The three session keys are all `pull()`ed, so subsequent replays of the callback URL find nothing and fail cleanly.

```php
if ( empty( $storedState ) || ! hash_equals( (string) $storedState, $returnedState ) ) {
    throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
}
```

`hash_equals()` protects against timing attacks — never compare secrets with `===`.

Missing verifier or user id also throw — this shouldn't happen unless the session was tampered with or the user hit `/callback` without going through `/connect` first.

### 2. Exchange the code

```php
$response = $this->http->asForm()->post( $endpoint, [
    'code'          => $code,
    'client_id'     => $this->config->getClientId(),
    'client_secret' => $this->config->getClientSecret(),
    'redirect_uri'  => $this->config->getRedirectUri(),
    'grant_type'    => 'authorization_code',
    'code_verifier' => (string) $verifier,
] );
```

Failed exchanges throw `OAuthException("Google code exchange failed: <error>")`. Common `<error>` values:

| Error | Cause |
|---|---|
| `invalid_grant` | Code already used, expired, or the redirect URI on the token call doesn't match the one used on the authorize call. |
| `redirect_uri_mismatch` | The redirect URI in your credential driver doesn't match one of the URIs on the Google OAuth client. |
| `invalid_client` | Client ID or secret is wrong. |

### 3. Decode `id_token`

Because the `openid` scope is in the baseline, Google returns an `id_token` JWT alongside the access token. The manager decodes the payload (base64url, JSON) to extract:

- `sub` → the stable Google user id, stored as `google_user_id`.
- `email` → the email address, stored as `email`.

```php
$parts   = explode( '.', $idToken );
$payload = base64_decode( strtr( $parts[ 1 ], '-_', '+/' ), true );
$claims  = json_decode( $payload, true );

return [
    $claims[ 'sub' ]   ?? null,
    $claims[ 'email' ] ?? null,
];
```

**The JWT signature is not verified.** The `id_token` arrived over TLS from Google's token endpoint on a connection we initiated — the claims are trustworthy for identity persistence (labeling the row so we can show `email` in the UI). We do **not** use them for authorization decisions, so signature validation would be pointless overhead. If your use case actually authorizes off the `id_token`, verify the signature externally with a library like `firebase/php-jwt`.

### 4. Persist the connection

```php
$connection = GoogleConnection::firstOrNew( [ 'user_id' => $userId ] );

$connection->google_user_id    = $googleUserId ?? $connection->google_user_id;
$connection->email             = $email ?? $connection->email;
$connection->access_token      = $payload[ 'access_token' ] ?? null;
$connection->token_type        = $payload[ 'token_type' ] ?? 'Bearer';
$connection->scopes            = $scopes;
$connection->expires_at        = $expiresAt;
$connection->status            = GoogleConnection::STATUS_CONNECTED;
$connection->disconnect_reason = null;

if ( ! empty( $payload[ 'refresh_token' ] ) ) {
    $connection->refresh_token = $payload[ 'refresh_token' ];
}

$connection->save();
```

Key details:

- **`firstOrNew`** — one connection per user. Re-connecting overwrites the existing row.
- **Refresh token preservation** — the setter is gated behind `! empty( $payload[ 'refresh_token' ] )`. Google only returns a refresh token on the first consent (and on subsequent consents when `prompt=consent` is used with a new grant). Incremental-consent regrants typically omit it. If we nulled it out on every callback we'd silently disable refresh for the user; instead we keep whatever we already have on file.
- **Access + refresh token encryption** — handled by the model's `casts()` returning `'encrypted'`. The DB sees ciphertext.
- **Scopes** — Google returns the effective scope list in the `scope` field of the response (space-separated). We split on `' '` and store as a JSON array; if the field is missing, we fall back to the union the scope registry returned.
- **`expires_at`** — computed as `now() + expires_in` seconds. If Google doesn't include `expires_in` (extremely rare), we store `null`, which the [token manager](Tokens) treats as "expired" and triggers a refresh on next use.
- **`disconnect_reason = null`** — a re-connect clears the "why was this disconnected?" note from any prior disconnect.

## Debugging a failing callback

Enable Laravel Boost's `read-log-entries` tool or run `php artisan pail` and repeat the flow. The most common failure signals:

- `OAuthException: OAuth state mismatch` → sessions aren't sticking across the redirect. Check `SESSION_DRIVER`, cookie domain, `SESSION_SAME_SITE`, HTTPS.
- `OAuthException: PKCE code verifier missing from session` → same as above, or the session was flushed between `/connect` and `/callback`.
- `OAuthException: Google code exchange failed: invalid_grant` → the code was reused (double-click on "Allow"?) or the redirect URIs disagree.
- `OAuthException: Google code exchange failed: redirect_uri_mismatch` → the URL stored in your credential driver doesn't match what's registered on the Google OAuth client. Character-exact match required.
