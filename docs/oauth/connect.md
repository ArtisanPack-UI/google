---
title: Connect
---

# Connect

`GoogleAuthController::connect()` and `OAuthManager::authorizationUrl()` build the redirect that sends a user to Google's consent screen.

## The controller

```php
public function connect( Request $request ): RedirectResponse
{
    $user = $request->user();

    if ( null === $user ) {
        abort( 401 );
    }

    $url = $this->oauth->authorizationUrl( $user->getAuthIdentifier() );

    return redirect()->away( $url );
}
```

Route: `GET /google/auth/connect` → `google.auth.connect`.

## Building the URL

`OAuthManager::authorizationUrl()` delegates to `buildAuthorizationUrl()`:

```php
$params = [
    'client_id'              => $this->config->getClientId(),
    'redirect_uri'           => $this->config->getRedirectUri(),
    'response_type'          => 'code',
    'scope'                  => implode( ' ', $scopes ),
    'access_type'            => 'offline',
    'prompt'                 => 'consent',
    'include_granted_scopes' => 'true',
    'state'                  => $state,
    'code_challenge'         => $challenge,
    'code_challenge_method'  => 'S256',
];
```

### Parameters explained

- **`response_type=code`** — Authorization Code grant. We exchange the code for tokens server-side.
- **`scope`** — Space-separated list from `ScopeRegistry::all()`. Baseline is `openid userinfo.email userinfo.profile`; service packages add via `ap.google.scopes`. See [[Scopes]].
- **`access_type=offline`** — Required to receive a `refresh_token`. Without this Google issues an access-token-only response and you'd have no way to refresh.
- **`prompt=consent`** — Forces the consent screen every time. This guarantees the `refresh_token` is included in the response — Google only re-issues it when the user actually clicks "Allow", not when they silently re-consent to previously approved scopes. Without `prompt=consent`, repeat connects from the same account get access tokens with no refresh token attached.
- **`include_granted_scopes=true`** — Google merges any newly granted scopes with existing ones. This matters for [[OAuth/Reauthorize|incremental consent]] and is harmless on a first connect.
- **`state`** — 40-char random string, stored in the session. On callback, compared with `hash_equals()` against the returned value. Defeats CSRF.
- **`code_challenge` + `code_challenge_method=S256`** — PKCE. The verifier (a URL-safe base64 of 64 random bytes) is stored in the session; the challenge is the URL-safe base64 of `SHA-256(verifier)`. Google returns the code to the redirect URI and requires the verifier on the token exchange call — so an attacker who intercepts the redirect can't exchange the code without also having the verifier.

## Session state

Three keys are written to the session:

| Key | Purpose |
|---|---|
| `google.oauth.state` | Verified against the callback's `state` parameter. |
| `google.oauth.verifier` | Sent as `code_verifier` in the token exchange. |
| `google.oauth.user_id` | The user we're building the connection for. Pulled out on callback so `handleCallback()` can attach the new tokens to the right row. |

All three are `pull()`ed (read + deleted) on callback, so a subsequent replay of the same callback URL fails cleanly.

## Not-configured errors

If the credential driver reports `isConfigured() === false`, `buildAuthorizationUrl()` throws:

```php
throw new OAuthException( __( 'Google OAuth credentials are not configured.' ) );
```

The default controller does not catch this — it will bubble up as a 500. If you're using the built-in routes, gate the "Connect Google" button behind a check first:

```blade
@if( Google::config()->isConfigured() )
    <a href="{{ route('google.auth.connect') }}">Connect Google</a>
@else
    <p>Google integration is not configured yet.</p>
@endif
```

Or wrap the controller with your own middleware that surfaces a friendlier error.

## Calling the manager directly

If your app has its own controller / URL structure and you set `google.routes.enabled = false`, resolve the manager and build the URL yourself:

```php
use ArtisanPackUI\Google\Facades\Google;

$url = Google::oauth()->authorizationUrl( $user->id );

return redirect()->away( $url );
```

You can also override the scopes for a specific flow:

```php
$url = Google::oauth()->authorizationUrl(
    $user->id,
    override: [ 'openid', 'https://www.googleapis.com/auth/analytics.readonly' ],
);
```

This is unusual — service packages normally register their scopes through the `ap.google.scopes` filter hook and rely on the union — but it's there when you need it.
