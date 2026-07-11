---
title: ScopeRegistry
---

# `ScopeRegistry`

`ArtisanPackUI\Google\Scopes\ScopeRegistry` collects the union of OAuth scopes required by dependent packages. Fully covered in [[Scopes]]; this page is the API reference.

## Signature

```php
namespace ArtisanPackUI\Google\Scopes;

class ScopeRegistry
{
    public function register( string $scope ): void;
    public function all(): array;
    public function missing( array $grantedScopes ): array;
    public function hasAllRequired( array $grantedScopes ): bool;
}
```

## The baseline

Every call to `all()` unions in three baseline scopes that are always required to identify the user:

- `openid`
- `https://www.googleapis.com/auth/userinfo.email`
- `https://www.googleapis.com/auth/userinfo.profile`

These are hardcoded on the class and can't be removed.

## Methods

### `register( string $scope ): void`

Imperatively register a scope. Trims whitespace and de-duplicates.

```php
Google::scopes()->register( 'https://www.googleapis.com/auth/gmail.send' );
```

Prefer the `ap.google.scopes` filter hook for package-supplied scopes — imperative registration is a convenience for apps that need to add a scope without wiring a service provider.

### `all(): array`

Return the full de-duplicated union: baseline + imperative + filter-hook contributions. Empty strings are dropped, everything is `strval`'d and trimmed.

```php
$scopes = Google::scopes()->all();
// ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile', ...]
```

### `missing( array $grantedScopes ): array`

Compute the set of required scopes not yet granted:

```php
$granted = $connection->grantedScopes();
$missing = Google::scopes()->missing( $granted );
```

Returns a `list<string>` — array_values'd for JSON safety.

### `hasAllRequired( array $grantedScopes ): bool`

Convenience: `[] === missing( $grantedScopes )`.

```php
if ( ! Google::scopes()->hasAllRequired( $granted ) ) {
    // Prompt user to reauthorize.
}
```

## The `ap.google.scopes` filter

`all()` invokes `Filter::apply( 'ap.google.scopes', [] )` and unions the return with the baseline + imperative scopes. Every hooked callback contributes to the final list.

```php
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.google.scopes', function ( array $scopes ): array {
    $scopes[] = 'https://www.googleapis.com/auth/analytics.readonly';
    return $scopes;
} );
```

Requires `artisanpack-ui/hooks ^1.2`.

## Related

- [[Scopes]] — usage and conventions.
- [[OAuth/Reauthorize]] — how `missing()` drives incremental consent.
