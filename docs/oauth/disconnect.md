---
title: Disconnect
---

# Disconnect

`POST /google/auth/disconnect` marks the current user's connection disconnected.

## The controller

```php
public function disconnect( Request $request ): RedirectResponse
{
    $user = $request->user();

    if ( null === $user ) {
        abort( 401 );
    }

    $state = ConnectionState::forUser( $user->getAuthIdentifier(), $this->scopes );

    $state->connection?->markDisconnected( __( 'Disconnected by user.' ) );

    return $this->redirectAfterConnect()->with( 'google.status', 'disconnected' );
}
```

Route: `POST /google/auth/disconnect` → `google.auth.disconnect`.

The `POST` method matters — GET links can be prefetched by browsers. Every UI that triggers this route should submit a form with a CSRF token:

```blade
<form method="POST" action="{{ route('google.auth.disconnect') }}">
    @csrf
    <button type="submit">Disconnect Google</button>
</form>
```

The React and Vue components handle this via `fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token } })` — see [Connection UI](Connection-UI).

## What `markDisconnected()` does

```php
public function markDisconnected( ?string $reason = null ): void
{
    $this->status            = self::STATUS_DISCONNECTED;
    $this->disconnect_reason = $reason;
    $this->save();
}
```

That's the whole thing. The row is kept — including the encrypted `refresh_token` — but `status` flips to `disconnected` and `disconnect_reason` records why.

Downstream effects:

- `GoogleConnection::isConnected()` returns `false`.
- `ConnectionState::isConnected` returns `false`, `email` and `grantedScopes` are cleared in the view model.
- `TokenManager::getValidAccessToken()` throws `TokenRefreshException("Google connection is disconnected.")` — service packages fail loud rather than silently making calls with a dead token.

## Local-only revocation

**The disconnect flow does not revoke the token with Google.** The refresh token stays valid on Google's side until:

- The user manually revokes it at [myaccount.google.com/permissions](https://myaccount.google.com/permissions).
- The token expires (Testing-mode apps: 7 days).
- Your app hits Google's revoke endpoint.

If you need remote revocation, POST to Google's revoke endpoint from your own code **before** calling `markDisconnected()`:

```php
use Illuminate\Support\Facades\Http;

$refreshToken = $connection->refresh_token; // decrypted by the cast

Http::asForm()->post( 'https://oauth2.googleapis.com/revoke', [
    'token' => $refreshToken,
] );

$connection->markDisconnected( 'Disconnected by user; refresh token revoked with Google.' );
```

The revoke endpoint accepts either an access token or a refresh token. Revoking the refresh token also invalidates all access tokens minted from it — one call kills the whole chain.

## Automatic disconnection

`markDisconnected()` is also called automatically in two places:

1. **Missing refresh token** — `TokenManager::refresh()` sees no refresh token on file, marks the connection disconnected with reason `"Missing refresh token."`, and throws.
2. **`invalid_grant` from the refresh endpoint** — the user revoked access on Google's side, or the refresh token expired. `TokenManager::refresh()` marks the connection disconnected with reason `"Refresh token revoked or expired."` and throws.

See [Tokens → Failure modes](Tokens#failure-modes) for the full sequence.

## Reconnecting

Since the row is kept (just flagged), reconnecting is a full run through `/connect`. `handleCallback()` uses `firstOrNew(['user_id' => $userId])`, so the existing row is updated in place:

- `access_token`, `refresh_token`, `expires_at`, `scopes` are overwritten with the new response.
- `status` flips back to `connected`.
- `disconnect_reason` is set to `null`.
- `google_user_id` and `email` are updated if the id_token includes them.

If the user reconnects with a different Google account, the row's `google_user_id` and `email` update accordingly — one row per app-user, not per Google account.

## Deleting the row entirely

The package never deletes `google_connections` rows on its own. If you want a "forget me" flow that also drops the historical connection record:

```php
use ArtisanPackUI\Google\Models\GoogleConnection;

GoogleConnection::where( 'user_id', $user->id )->delete();
```

Follow that with the Google revoke call above if you also want to clear the grant on Google's side.
