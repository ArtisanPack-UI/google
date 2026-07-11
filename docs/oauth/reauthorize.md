---
title: Reauthorize
---

# Reauthorize

When a new service package is installed after the user is already connected, the [[Scopes|scope registry]] starts returning scopes the connection doesn't hold. Rather than force a full re-consent, the package supports **incremental consent** — Google's `include_granted_scopes=true` lets us ask for only the missing scopes and have the new grant merge with the existing one.

## The route

`GET /google/auth/reauthorize` → `google.auth.reauthorize` → `GoogleAuthController::reauthorize()`.

```php
public function reauthorize( Request $request ): RedirectResponse
{
    $user = $request->user();

    if ( null === $user ) {
        abort( 401 );
    }

    $state = ConnectionState::forUser( $user->getAuthIdentifier(), $this->scopes );

    if ( ! $state->isConnected ) {
        return redirect()->route( 'google.auth.connect' );
    }

    $url = $this->oauth->reauthorizationUrl(
        $user->getAuthIdentifier(),
        $state->grantedScopes,
    );

    return redirect()->away( $url );
}
```

Note the not-connected fallback: hitting `/reauthorize` without an existing connection redirects to `/connect` — so onboarding, gating, and analytics that hang off `google.auth.connect` still fire on a fresh consent.

## `reauthorizationUrl()`

```php
public function reauthorizationUrl( int|string $userId, array $grantedScopes ): string
{
    $missing   = $this->scopes->missing( $grantedScopes );
    $requested = [] === $missing ? $this->scopes->all() : $missing;

    return $this->buildAuthorizationUrl( $userId, $requested );
}
```

Two behaviors worth noting:

1. **Only the delta is requested** when there are missing scopes. Google merges the new grant with the existing one because we always send `include_granted_scopes=true`, so the user's `grantedScopes` grows without them re-approving old ones.
2. **Fallback to the full union** when nothing is missing. In practice callers should short-circuit before hitting this method (see [[Connection UI]] — the reauthorize banner only shows when `needsReauthorize === true`), but the fallback keeps the API predictable: it always returns a valid authorize URL.

## Detecting the need

The [[Connection Model|`ConnectionState`]] view model exposes this as a boolean:

```php
$state = ConnectionState::forUser( $user->id, app( ScopeRegistry::class ) );

if ( $state->needsReauthorize ) {
    // show the reauthorize button
}
```

Or client-side, from the JSON status endpoint:

```js
const { needsReauthorize, missingScopes } = await fetch('/google/auth/status').then(r => r.json());

if (needsReauthorize) {
    // show the reauthorize button, list missingScopes
}
```

## Refresh-token behavior on reauthorize

Google typically **does not** return a fresh `refresh_token` on an incremental-consent regrant (it does return a new `access_token`). `handleCallback()` gates the setter on `! empty( $payload[ 'refresh_token' ] )`, so the existing refresh token is preserved:

```php
if ( ! empty( $payload[ 'refresh_token' ] ) ) {
    $connection->refresh_token = $payload[ 'refresh_token' ];
}
```

This matters because refresh tokens issued in Google's "Testing" mode expire after 7 days. If your app is in Testing mode and users hit `/reauthorize` after that window, the existing refresh token will be dead and the new response won't include a replacement — you'll need to publish the app or send them through `/connect` (which uses `prompt=consent` and always gets a fresh refresh token).

## When to trigger

- The user visits your integrations settings page and the JSON status endpoint returns `needsReauthorize: true`.
- A service package that requires new scopes was just installed.
- A background job noticed a `google_403 insufficient_scope` error and wants to prompt the user to grant more.

Whenever you programmatically build the URL from your own UI:

```php
use ArtisanPackUI\Google\Facades\Google;

$connection = $user->googleConnection;
$granted    = $connection->grantedScopes();
$url        = Google::oauth()->reauthorizationUrl( $user->id, $granted );

return redirect()->away( $url );
```

## What if the user denies?

Same as any OAuth error path — Google redirects back to `/callback` with `?error=access_denied`. The default controller flashes `google.error` and sends them to `redirect_after_error`. The existing connection is untouched: the user's granted scopes stay as they were, `needsReauthorize` stays `true`, and the UI keeps prompting.
