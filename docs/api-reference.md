---
title: API Reference
---

# API Reference

The public surface of `artisanpack-ui/google`.

Sub-pages by class:

- [[API Reference/Google|`Google` — the facade / helper root]]
- [[API Reference/OAuth Manager|`OAuthManager`]]
- [[API Reference/Token Manager|`TokenManager`]]
- [[API Reference/Scope Registry|`ScopeRegistry`]]
- [[API Reference/Configuration Repository|`ConfigurationRepository`]] (contract + three drivers)
- [[API Reference/Connection Model|`GoogleConnection`]]
- [[API Reference/Connection State|`ConnectionState`]]
- [[API Reference/Exceptions|Exceptions]]

## The facade

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config();    // ConfigurationRepository
Google::scopes();    // ScopeRegistry
Google::tokens();    // TokenManager
Google::oauth();     // OAuthManager
```

Every accessor returns a singleton — the same instance is shared across the request lifecycle. The `Google` class itself just aggregates the four managers so callers have a single entry point.

## The helper

```php
google();  // same as app( 'google' )
```

Returns the `ArtisanPackUI\Google\Google` instance. Equivalent to using the facade — pick whichever style matches your codebase.

## Container bindings

The service provider registers:

| Abstract | Concrete | Scope |
|---|---|---|
| `google` | `ArtisanPackUI\Google\Google` | Singleton |
| `ConfigurationRepository::class` | `ConfigDriver` / `DatabaseDriver` / `CmsSettingsDriver` (per `google.driver`) | Bind (re-resolved per lookup) |
| `ConfigDriver::class` | Singleton |
| `DatabaseDriver::class` | Singleton |
| `CmsSettingsDriver::class` | Singleton |
| `ScopeRegistry::class` | Singleton |
| `TokenManager::class` | Singleton |
| `OAuthManager::class` | Singleton |

Rebind `ConfigurationRepository` to swap in a custom driver — the `Google` class resolves it fresh on every `Google::config()` call.

## Namespace map

```
ArtisanPackUI\Google\
├── Google.php                          — the aggregator
├── helpers.php                         — google() function
├── Facades\
│   └── Google.php                      — facade
├── Configuration\
│   ├── ConfigDriver.php
│   ├── DatabaseDriver.php
│   └── CmsSettingsDriver.php
├── Contracts\
│   └── ConfigurationRepository.php
├── OAuth\
│   └── OAuthManager.php
├── Scopes\
│   └── ScopeRegistry.php
├── Tokens\
│   └── TokenManager.php
├── Models\
│   └── GoogleConnection.php
├── Support\
│   └── ConnectionState.php
├── Http\
│   └── Controllers\
│       └── GoogleAuthController.php    — the four routes
├── Livewire\
│   └── ConnectionManager.php           — <livewire:google-connection-manager />
└── Exceptions\
    ├── OAuthException.php
    └── TokenRefreshException.php
```

## Public routes

Reference: [[OAuth Flow#Routes]]

## Public views

- `google::livewire.connection-manager` — the Livewire component's view. Publish with `--tag=google-views`.

## Publish tags

| Tag | What it publishes |
|---|---|
| `google-config` | `config/google.php` |
| `google-migrations` | Both migrations to `database/migrations/`. |
| `google-views` | `resources/views/livewire/connection-manager.blade.php` → `resources/views/vendor/google/`. |
| `google-js` | `resources/js/` (React, Vue, shared) → `resources/js/vendor/google/`. |

## Filter hooks

| Hook | Contract | Purpose |
|---|---|---|
| `ap.google.scopes` | Filter — receives and returns `array<int, string>` | Contribute scopes to the [[Scopes|registry]]. Fires inside `ScopeRegistry::all()`. |

## Event listeners (Livewire)

| Event | Handler |
|---|---|
| `google-connection-updated` | Triggers `$refresh` on `<livewire:google-connection-manager />`. Dispatch from your own code after a same-page state change. |

---
Continue to [[Testing]] →
