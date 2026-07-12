---
title: Connection Model
---

# Connection Model

The `GoogleConnection` Eloquent model represents a single connected Google account for a user. One row per user (`user_id` has a unique index) — connecting a different Google account overwrites the same row.

## Schema

```php
Schema::create( 'google_connections', function ( Blueprint $table ): void {
    $table->id();
    $table->unsignedBigInteger( 'user_id' );
    $table->string( 'google_user_id' )->nullable();
    $table->string( 'email' )->nullable();
    $table->text( 'access_token' )->nullable();     // encrypted
    $table->text( 'refresh_token' )->nullable();    // encrypted
    $table->string( 'token_type' )->default( 'Bearer' );
    $table->text( 'scopes' )->nullable();           // JSON array
    $table->timestamp( 'expires_at' )->nullable();
    $table->string( 'status' )->default( 'connected' );
    $table->text( 'disconnect_reason' )->nullable();
    $table->timestamps();

    $table->unique( 'user_id' );
    $table->index( [ 'user_id', 'status' ] );
} );
```

## Column reference

| Column | Type | Encryption | Purpose |
|---|---|---|---|
| `id` | bigint | — | Primary key. |
| `user_id` | bigint | — | FK to your `users` table (or the model in `google.user_model`). Unique. |
| `google_user_id` | string | — | Stable Google account id (`sub` claim from the `id_token`). Useful for detecting "user reconnected with a different Google account". |
| `email` | string | — | Email address from the `id_token`'s `email` claim. Displayed in the connection UI as "Connected as {email}". |
| `access_token` | text | `encrypted` cast | Bearer token used for API calls. Refreshed transparently by the [token manager](Tokens). |
| `refresh_token` | text | `encrypted` cast | Long-lived token used to mint new access tokens. Preserved across incremental-consent regrants. |
| `token_type` | string | — | Always `Bearer` in practice. |
| `scopes` | text | `array` cast | JSON list of scopes Google returned in the exchange response. Used by the scope registry to compute `missing()`. |
| `expires_at` | timestamp | `datetime` cast | When the current `access_token` expires. Treated as expired 60s before this value. |
| `status` | string | — | Either `'connected'` or `'disconnected'`. Use `GoogleConnection::STATUS_CONNECTED` / `STATUS_DISCONNECTED` constants. |
| `disconnect_reason` | text | — | Why the connection is disconnected. Set by `markDisconnected( $reason )`. `null` while connected. |

## Casts

```php
protected function casts(): array
{
    return [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes'        => 'array',
        'expires_at'    => 'datetime',
    ];
}
```

- **`encrypted`** — Eloquent transparently encrypts on save, decrypts on read. Depends on `APP_KEY`.
- **`array`** — Stored as JSON, exposed as a PHP array. `$connection->scopes[] = 'new-scope'` and save works as expected.
- **`datetime`** — Returns a Carbon instance.

## Relationships

```php
public function user(): BelongsTo
{
    /** @var class-string<Model> $userModel */
    $userModel = config( 'google.user_model', 'App\\Models\\User' );

    return $this->belongsTo( $userModel, 'user_id' );
}
```

The user model is resolved from config, so:

```php
$connection->user;             // instance of your configured user model
$user->googleConnection;       // define this yourself if you want the inverse
```

The package doesn't add an inverse `hasOne` to your User model automatically — add it if you like:

```php
// app/Models/User.php
use ArtisanPackUI\Google\Models\GoogleConnection;

public function googleConnection(): HasOne
{
    return $this->hasOne( GoogleConnection::class );
}
```

## Methods

### `isConnected(): bool`

```php
public function isConnected(): bool
{
    return self::STATUS_CONNECTED === $this->status;
}
```

Whether the connection is usable for API calls. Cheap check; use freely.

### `isExpired(): bool`

```php
public function isExpired(): bool
{
    if ( null === $this->expires_at ) {
        return true;
    }

    return $this->expires_at->copy()->subSeconds( 60 )->isPast();
}
```

Whether the stored access token is expired **or will expire in the next 60 seconds**. The token manager uses this to decide whether to refresh — don't second-guess it in your own code.

### `grantedScopes(): array`

```php
public function grantedScopes(): array
{
    $scopes = $this->scopes;

    if ( ! is_array( $scopes ) ) {
        return [];
    }

    return array_values( array_map( 'strval', $scopes ) );
}
```

The scopes granted by Google to this connection, normalized to a list. Handles the null/mixed-type case that a raw `$this->scopes` access could hit.

Pass this to `Google::scopes()->missing( $granted )` when computing what to request in an incremental-consent flow.

### `markDisconnected( ?string $reason = null ): void`

```php
public function markDisconnected( ?string $reason = null ): void
{
    $this->status            = self::STATUS_DISCONNECTED;
    $this->disconnect_reason = $reason;
    $this->save();
}
```

Marks the connection disconnected. Local-only — does not revoke the token with Google. Called automatically by the token manager on `invalid_grant` or missing refresh token, and by the disconnect controller on user request.

## Constants

- `GoogleConnection::STATUS_CONNECTED = 'connected'`
- `GoogleConnection::STATUS_DISCONNECTED = 'disconnected'`

Use these instead of string literals when comparing.

## `ConnectionState` view model

Working with connections in a UI context? Use `ArtisanPackUI\Google\Support\ConnectionState` — it normalizes the connection + scope registry into a single object that's safe to pass to Blade / JSON / anywhere:

```php
use ArtisanPackUI\Google\Support\ConnectionState;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;

$state = ConnectionState::forUser( $user->id, app( ScopeRegistry::class ) );

$state->isConnected;         // bool
$state->email;               // string|null; null when not connected
$state->grantedScopes;       // list<string>
$state->requiredScopes;      // list<string> (from the registry)
$state->missingScopes;       // list<string>
$state->needsReauthorize;    // bool — true when connected AND missingScopes is non-empty
$state->connection;          // GoogleConnection|null
```

The Livewire component, the JSON status endpoint, and any future server-rendered surface all resolve their view data through this class — so rules like "hide the reauthorize banner when disconnect_reason is set" or "clear the email when not connected" only ever have to change here.

`toArray()` gives you the JSON shape consumed by the React and Vue components.
