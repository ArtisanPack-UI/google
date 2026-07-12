---
title: Building Your Own UI
---

# Building Your Own UI

The Livewire, React, and Vue components are convenience wrappers. Any UI framework can talk to the same endpoints — this page is the contract they consume.

## Endpoints

Every component uses exactly four routes:

| Route | Method | Purpose | Response |
|---|---|---|---|
| `/google/auth/status` | GET | Read the current user's state. | JSON payload (see below). |
| `/google/auth/connect` | GET | Start a fresh consent. | 302 to Google. |
| `/google/auth/reauthorize` | GET | Start an incremental consent. | 302 to Google, or to `/google/auth/connect` if not yet connected. |
| `/google/auth/disconnect` | POST | Mark disconnected. | 302 to `redirect_after_connect`. |

All five expect an authenticated user — unauthenticated calls to `status` return `401 { message: "Unauthenticated." }`, and the others `abort(401)`.

## Status payload

```json
{
    "connected": true,
    "email": "you@example.com",
    "grantedScopes": [
        "openid",
        "https://www.googleapis.com/auth/userinfo.email",
        "https://www.googleapis.com/auth/analytics.readonly"
    ],
    "requiredScopes": [
        "openid",
        "https://www.googleapis.com/auth/userinfo.email",
        "https://www.googleapis.com/auth/analytics.readonly",
        "https://www.googleapis.com/auth/webmasters.readonly"
    ],
    "missingScopes": [
        "https://www.googleapis.com/auth/webmasters.readonly"
    ],
    "needsReauthorize": true,
    "disconnectReason": null,
    "urls": {
        "connect":     "https://your-app.test/google/auth/connect",
        "reauthorize": "https://your-app.test/google/auth/reauthorize",
        "disconnect":  "https://your-app.test/google/auth/disconnect"
    }
}
```

### Fields

| Field | Type | Meaning |
|---|---|---|
| `connected` | boolean | `true` when the user has a `GoogleConnection` with `status = 'connected'`. |
| `email` | string \| null | Email from the `id_token` when connected; `null` otherwise. |
| `grantedScopes` | string[] | Scopes on file for the current connection. Empty array when disconnected. |
| `requiredScopes` | string[] | Full union from the [scope registry](Scopes) — what the connect URL would ask for. |
| `missingScopes` | string[] | `requiredScopes` − `grantedScopes`. Non-empty when service packages have been added since the initial consent. |
| `needsReauthorize` | boolean | `true` when `connected && missingScopes.length > 0`. Use to gate the "Reauthorize" button. |
| `disconnectReason` | string \| null | When disconnected, why. Values include `Disconnected by user.`, `Missing refresh token.`, `Refresh token revoked or expired.` |
| `urls.connect` | string | Absolute URL for `route('google.auth.connect')`. |
| `urls.reauthorize` | string | Absolute URL for `route('google.auth.reauthorize')`. |
| `urls.disconnect` | string | Absolute URL for `route('google.auth.disconnect')`. |

Rely on `urls` from the payload rather than hard-coding paths — this survives changes to `google.routes.prefix`.

## Minimal HTML + fetch example

```html
<div id="google-connection"></div>

<script>
    const el = document.getElementById( 'google-connection' );

    async function refresh() {
        const res    = await fetch( '/google/auth/status', { credentials: 'same-origin' } );
        const state  = await res.json();

        if ( state.connected ) {
            el.innerHTML = `
                <p>Connected as ${state.email}</p>
                ${state.needsReauthorize ? `<a href="${state.urls.reauthorize}">Reauthorize (${state.missingScopes.length} new)</a>` : ''}
                <button data-action="disconnect">Disconnect</button>
            `;

            el.querySelector( 'button' ).addEventListener( 'click', async () => {
                await fetch( state.urls.disconnect, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector( 'meta[name=csrf-token]' ).content },
                } );
                refresh();
            } );
        } else {
            el.innerHTML = `<a href="${state.urls.connect}">Connect Google</a>`;
        }
    }

    refresh();
</script>
```

## Server-rendered custom UI

Prefer a fully server-rendered surface? Skip the JSON endpoint entirely and resolve `ConnectionState` in your controller:

```php
use ArtisanPackUI\Google\Support\ConnectionState;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;

public function integrations( Request $request )
{
    $state = ConnectionState::forUser(
        $request->user()->getAuthIdentifier(),
        app( ScopeRegistry::class ),
    );

    return view( 'settings.integrations', compact( 'state' ) );
}
```

In your Blade:

```blade
@if( $state->isConnected )
    <p>{{ __( 'Connected as :email', [ 'email' => $state->email ] ) }}</p>

    @if( $state->needsReauthorize )
        <a href="{{ route('google.auth.reauthorize') }}">
            {{ __( ':count new scopes require reauthorization', [ 'count' => count( $state->missingScopes ) ] ) }}
        </a>
    @endif

    <form method="POST" action="{{ route('google.auth.disconnect') }}">
        @csrf
        <button type="submit">{{ __( 'Disconnect' ) }}</button>
    </form>
@else
    <a href="{{ route('google.auth.connect') }}">{{ __( 'Connect Google' ) }}</a>
@endif
```

## CSRF

- **Connect and reauthorize**: GET requests, no CSRF token needed.
- **Disconnect**: POST — send `X-CSRF-TOKEN` in the headers **or** an `_token` form field.

Laravel's `VerifyCsrfToken` middleware is applied via the default `web` middleware group, so the token is required unless you explicitly exclude the route.

## Skipping the built-in routes

If you'd rather own the URL structure entirely, set `google.routes.enabled = false` and mount your own controller:

```php
Route::middleware( 'auth' )->group( function (): void {
    Route::get( '/integrations/google/connect', function () {
        return redirect()->away( Google::oauth()->authorizationUrl( auth()->id() ) );
    } );

    Route::get( '/integrations/google/callback', function ( Request $request ) {
        Google::oauth()->handleCallback( $request->query( 'code' ), $request->query( 'state' ) );
        return redirect( '/settings' );
    } );

    // ... etc
} );
```

The manager services still resolve normally; you're just replacing the four controller actions.
