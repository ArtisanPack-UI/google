---
title: Scopes
---

# Scopes

Google OAuth uses **scopes** to gate what your access token can do. `artisanpack-ui/google` lets any installed service package contribute the scopes it needs and unions them into a single consent screen — the user only sees one prompt, no matter how many packages depend on Google APIs.

## The registry

`ArtisanPackUI\Google\Scopes\ScopeRegistry` collects three sources:

1. **The baseline** — always required to identify the connecting user:
   - `openid`
   - `https://www.googleapis.com/auth/userinfo.email`
   - `https://www.googleapis.com/auth/userinfo.profile`
2. **Filter-hook contributions** via `ap.google.scopes`.
3. **Imperative registrations** via `ScopeRegistry::register( $scope )`.

`ScopeRegistry::all()` returns the de-duplicated, trimmed union. Empty strings are dropped, and duplicates across the three sources are collapsed.

## Registering scopes from a service package

Preferred: hook the `ap.google.scopes` filter in your service package's `boot()` method:

```php
use ArtisanPackUI\Hooks\Facades\Filter;

class AnalyticsGoogleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Filter::add( 'ap.google.scopes', function ( array $scopes ): array {
            $scopes[] = 'https://www.googleapis.com/auth/analytics.readonly';
            return $scopes;
        } );
    }
}
```

The registry calls `Filter::apply('ap.google.scopes', [])` inside `all()`, so every hooked callback contributes to the union. The order the callbacks fire doesn't matter — the union is de-duplicated at the end.

## Registering scopes from application code

For app-level scopes without a service provider:

```php
use ArtisanPackUI\Google\Facades\Google;

Google::scopes()->register( 'https://www.googleapis.com/auth/gmail.send' );
```

Register from anywhere runs before an authorize URL is built — usually inside a service provider's `boot()`.

## Reading the current union

```php
use ArtisanPackUI\Google\Facades\Google;

$scopes = Google::scopes()->all();
// ['openid', 'https://www.googleapis.com/auth/userinfo.email', ...]
```

## Incremental consent: `missing()` and `hasAllRequired()`

When a service package is installed after a user is already connected, the registry starts returning scopes the connection doesn't hold. Two methods help you detect this:

```php
$granted = $connection->grantedScopes();

Google::scopes()->missing( $granted );          // ['https://www.googleapis.com/auth/new-scope']
Google::scopes()->hasAllRequired( $granted );   // false
```

The [[Connection Model|`ConnectionState`]] view model exposes this pre-computed as `missingScopes` and `needsReauthorize`, so most callers won't touch the registry directly.

See [[OAuth/Reauthorize]] for how to build the incremental-consent URL from a granted-scope list.

## Scope hygiene

Google's OAuth policies are strict about scopes:

- **Only request what you use.** Adding scopes you don't need triggers a verification review for external apps.
- **Consent screen must list every scope.** If a scope isn't in the "OAuth consent screen → Scopes" list on the Cloud Console, Google rejects the authorize request with `invalid_scope` at runtime. Add it there whenever a service package needs one.
- **Sensitive and restricted scopes require verification.** External apps that use scopes marked "sensitive" or "restricted" must complete Google's verification review before non-test-user accounts can consent.

## Common scopes by service package

| Service package | Scope(s) it registers |
|---|---|
| `artisanpack-ui/analytics-google` | `https://www.googleapis.com/auth/analytics.readonly` |
| `artisanpack-ui/google-search-console` | `https://www.googleapis.com/auth/webmasters.readonly` |
| `artisanpack-ui/google-tag-manager` | `https://www.googleapis.com/auth/tagmanager.readonly`, `https://www.googleapis.com/auth/tagmanager.edit.containers` |

Refer to each service package's docs for the authoritative list.

## Overriding scopes for a specific flow

`OAuthManager::authorizationUrl()` accepts an optional scope override:

```php
$url = Google::oauth()->authorizationUrl(
    $user->id,
    override: [
        'openid',
        'https://www.googleapis.com/auth/analytics.readonly',
    ],
);
```

Useful for a "connect just for analytics" one-off flow that shouldn't require every service package's scopes. In practice this is rarely needed — the union model gets you one consent screen per user, which is almost always what you want.
