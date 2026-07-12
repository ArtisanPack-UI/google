---
title: ConnectionState
---

# `ConnectionState`

`ArtisanPackUI\Google\Support\ConnectionState` is the shared view model consumed by every connection-management surface (Livewire, React, Vue, custom Blade). Rules like "hide the reauthorize banner when disconnected" or "clear the email when not connected" only ever have to change here.

## Signature

```php
namespace ArtisanPackUI\Google\Support;

class ConnectionState
{
    public function __construct(
        public readonly ?GoogleConnection $connection,
        public readonly bool $isConnected,
        public readonly ?string $email,
        public readonly array $grantedScopes,
        public readonly array $requiredScopes,
        public readonly array $missingScopes,
        public readonly bool $needsReauthorize,
    );

    public static function forUser( int|string|null $userId, ScopeRegistry $scopes ): self;
    public static function forConnection( ?GoogleConnection $connection, ScopeRegistry $scopes ): self;

    public function toArray(): array;
}
```

## Properties

Every property is `public readonly`:

| Property | Type | Meaning |
|---|---|---|
| `connection` | `GoogleConnection\|null` | The underlying model, or `null` when no connection exists. |
| `isConnected` | `bool` | Whether the connection exists and its status is `connected`. |
| `email` | `string\|null` | `$connection->email` when connected, `null` otherwise. |
| `grantedScopes` | `list<string>` | Scopes the connection holds. Empty when disconnected. |
| `requiredScopes` | `list<string>` | Full union from the [registry](Scopes). |
| `missingScopes` | `list<string>` | `requiredScopes` − `grantedScopes`. |
| `needsReauthorize` | `bool` | `isConnected && missingScopes !== []`. |

Once constructed, the state is immutable — build a new one to reflect DB changes.

## Static constructors

### `forUser( int|string|null $userId, ScopeRegistry $scopes ): self`

Load the connection for `$userId` and build state. `null` `$userId` returns state with `connection = null` and `isConnected = false` — safe to call from unauthenticated contexts.

```php
$state = ConnectionState::forUser( auth()->id(), app( ScopeRegistry::class ) );
```

### `forConnection( ?GoogleConnection $connection, ScopeRegistry $scopes ): self`

Build state from an already-loaded connection. Cheaper than `forUser()` when you've already eager-loaded the relationship.

```php
$state = ConnectionState::forConnection( $user->googleConnection, app( ScopeRegistry::class ) );
```

## Methods

### `toArray(): array`

Serialize as JSON for API responses:

```php
[
    'connected'        => bool,
    'email'            => ?string,
    'grantedScopes'    => list<string>,
    'requiredScopes'   => list<string>,
    'missingScopes'    => list<string>,
    'needsReauthorize' => bool,
    'disconnectReason' => ?string,
]
```

Note `disconnectReason` — pulled from `$connection?->disconnect_reason` in `toArray()` since the property itself lives on the model, not the state.

The `GoogleAuthController::status()` endpoint uses this method plus a `urls` block. See [Custom UI → Status payload](Connection-UI-Custom#status-payload) for the full shape.

## Consumer surfaces

Every one of these types resolves its view data through `ConnectionState`:

- `ArtisanPackUI\Google\Livewire\ConnectionManager` (Livewire component).
- `ArtisanPackUI\Google\Http\Controllers\GoogleAuthController::status()` (JSON endpoint).
- `ArtisanPackUI\Google\Http\Controllers\GoogleAuthController::reauthorize()` (to decide fallback to `/connect`).
- `ArtisanPackUI\Google\Http\Controllers\GoogleAuthController::disconnect()` (to load the connection to mark).

If you're building a custom Blade / SPA surface, join the club — resolve `ConnectionState::forUser()` in your controller and pass it to the view.
