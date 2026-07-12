---
title: GoogleConnection
---

# `GoogleConnection`

`ArtisanPackUI\Google\Models\GoogleConnection` is the Eloquent model that represents a single connected Google account for a user. See [Connection Model](Connection-Model) for the full schema breakdown; this page is the API reference.

## Signature

```php
namespace ArtisanPackUI\Google\Models;

class GoogleConnection extends Model
{
    public const STATUS_CONNECTED    = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $table = 'google_connections';

    protected $fillable = [
        'user_id',
        'google_user_id',
        'email',
        'access_token',
        'refresh_token',
        'token_type',
        'scopes',
        'expires_at',
        'status',
        'disconnect_reason',
    ];

    public function user(): BelongsTo;
    public function isExpired(): bool;
    public function isConnected(): bool;
    public function grantedScopes(): array;
    public function markDisconnected( ?string $reason = null ): void;

    protected function casts(): array;
}
```

## Constants

- `STATUS_CONNECTED = 'connected'`
- `STATUS_DISCONNECTED = 'disconnected'`

## Methods

### `user(): BelongsTo`

The user that owns this Google connection. Target model is resolved from `config('google.user_model')` at call time — defaults to `App\Models\User`.

### `isExpired(): bool`

Whether the stored access token is expired or expires within 60 seconds:

```php
if ( null === $this->expires_at ) {
    return true;
}

return $this->expires_at->copy()->subSeconds( 60 )->isPast();
```

`null` is treated as expired to force a refresh on the next call.

### `isConnected(): bool`

`self::STATUS_CONNECTED === $this->status`. Cheap; use freely.

### `grantedScopes(): array`

The scopes granted by Google, normalized to a `list<string>`. Handles the null case that a raw `$this->scopes` access might return.

### `markDisconnected( ?string $reason = null ): void`

Flip `status` to `disconnected`, set `disconnect_reason`, save. Called automatically by `TokenManager` on refresh failure, and by `GoogleAuthController::disconnect()` on user request. Local-only — does not revoke with Google.

## Casts

```php
[
    'access_token'  => 'encrypted',
    'refresh_token' => 'encrypted',
    'scopes'        => 'array',
    'expires_at'    => 'datetime',
]
```

- `encrypted` — transparent en/decryption via Laravel's `Encrypter`. Depends on `APP_KEY`.
- `array` — stored as JSON, exposed as PHP array.
- `datetime` — Carbon instance.

## Related

- [Connection Model](Connection-Model) — schema breakdown, relationship setup, testing.
- [`ConnectionState`](API-Reference-Connection-State) — the view model that wraps this for UIs.
