---
title: Database Driver
---

# `database` Driver

Stores app credentials in the `google_configurations` table. The `client_secret` column is encrypted with Laravel's `Encrypter` (`APP_KEY`) before it's written.

## Selecting

```env
GOOGLE_CONFIG_DRIVER=database
```

The values from `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` are **ignored** when the driver is `database` — the driver reads from the database only.

## Schema

Published by `php artisan vendor:publish --tag=google-migrations` and applied by `php artisan migrate`:

```php
Schema::create( 'google_configurations', function ( Blueprint $table ): void {
    $table->id();
    $table->string( 'client_id' )->nullable();
    $table->text( 'client_secret' )->nullable();  // stores ciphertext
    $table->string( 'redirect_uri' )->nullable();
    $table->timestamps();
} );
```

The driver keeps at most one row — subsequent `save()` calls update the existing row rather than inserting.

## Saving credentials

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config()->save( [
    'client_id'     => '1234567890-abcdef.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-xxxxxxxxxxxxxxxxxxxx',
    'redirect_uri'  => 'https://your-app.test/google/auth/callback',
] );
```

Pass plaintext values. The driver encrypts the `client_secret` before writing.

## Reading credentials

Reads go through the same `ConfigurationRepository` methods:

```php
$config = app( \ArtisanPackUI\Google\Contracts\ConfigurationRepository::class );

$config->getClientId();      // string|null (plaintext)
$config->getClientSecret();  // string|null (decrypted plaintext)
$config->getRedirectUri();   // string|null
$config->isConfigured();     // true when all three are non-empty
```

Values are cached per-request after first read; a subsequent `save()` in the same request invalidates the cache.

## `APP_KEY` rotation

The `client_secret` column stores ciphertext produced by `Encrypter::encryptString()` — decryption depends on the current `APP_KEY`. If you rotate `APP_KEY` without re-encrypting the row, the driver **logs a warning and treats the row as unconfigured**:

```
artisanpack-ui/google: failed to decrypt stored client_secret; treating as unconfigured.
Was APP_KEY rotated without re-encrypting the row?
```

The `isConfigured()` method returns `false`, and any attempt to build an authorize URL will throw `OAuthException("Google OAuth credentials are not configured.")`. Users see the "not configured" state instead of a mysterious 500.

**Recommended migration path when rotating `APP_KEY`**:

1. Decrypt every existing secret with the old key, keep in memory.
2. Rotate `APP_KEY`.
3. Re-encrypt and rewrite via `Google::config()->save()`.

You can do this in a one-off migration or an Artisan command.

## When to use it

- Multi-tenant apps where each tenant might have their own OAuth client (bind the driver differently per-tenant).
- Admin dashboards where operators paste in credentials without a deploy.
- Environments where credential rotation happens in-app, not through the deploy pipeline.

## When not to use it

- Simple single-tenant apps — the `config` driver is one less moving part.
- Apps where the CMS Settings module is already the source of truth for site-level config — use the [`cms` driver](Drivers-CMS) instead.

## Multi-tenant use

The driver's `$table` and `$connection` are injected at construction time (`ConnectionInterface`, resolved from `$app['db']->connection()`), so re-binding it per-tenant is straightforward:

```php
$this->app->singleton( DatabaseDriver::class, function ( Application $app ): DatabaseDriver {
    $connection = $app[ 'db' ]->connection( 'tenant' );  // your tenant connection

    return new DatabaseDriver( $connection, $app[ 'encrypter' ] );
} );
```

Every tenant then gets its own `google_configurations` row on its own database connection.

## Testing

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config()->save( [
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'redirect_uri'  => 'https://tests.test/google/auth/callback',
] );

expect( Google::config()->isConfigured() )->toBeTrue();
expect( Google::config()->getClientSecret() )->toBe( 'test-client-secret' );
```

Make sure `RefreshDatabase` runs before assertions on the row, and that `config('google.driver')` is set to `database` — the default in Orchestra Testbench is `config`.
