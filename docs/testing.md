---
title: Testing
---

# Testing

The package ships with a Pest test suite you can use as a reference. This page collects patterns for testing your own code against `artisanpack-ui/google`.

## Test bootstrap

The package's own tests use Orchestra Testbench with a base `TestCase` in `tests/`. To wire up an app-level test that talks to the package:

```php
use ArtisanPackUI\Google\GoogleServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders( $app ): array
    {
        return [ GoogleServiceProvider::class ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom( __DIR__ . '/../vendor/artisanpack-ui/google/database/migrations' );
    }
}
```

Or use the `RefreshDatabase` trait in a normal Laravel `TestCase` — the package registers its migrations via `$this->loadMigrationsFrom()` in `boot()`, so `php artisan migrate` in the test DB is enough.

## Testing OAuth flows

Fake Google's endpoints:

```php
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://oauth2.googleapis.com/token' => Http::sequence()
        ->push( [
            'access_token'  => 'initial-access-token',
            'refresh_token' => 'a-refresh-token',
            'expires_in'    => 3600,
            'token_type'    => 'Bearer',
            'scope'         => 'openid https://www.googleapis.com/auth/userinfo.email',
            'id_token'      => makeIdToken( sub: 'g-123', email: 'you@example.com' ),
        ] ),
] );
```

Where `makeIdToken()` builds a minimal id_token payload (base64url of `{header}.{payload}.{signature}` — the signature can be dummy since the package doesn't verify it):

```php
function makeIdToken( string $sub, string $email ): string
{
    $header  = base64url( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
    $payload = base64url( json_encode( [ 'sub' => $sub, 'email' => $email ] ) );

    return "{$header}.{$payload}.signature";
}

function base64url( string $raw ): string
{
    return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}
```

Then drive the flow:

```php
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\Models\GoogleConnection;

$user = User::factory()->create();

// Pretend the /connect handler ran.
$url = Google::oauth()->authorizationUrl( $user->id );

// Extract state from the session; simulate the callback.
$state = session( 'google.oauth.state' );

Google::oauth()->handleCallback( 'test-auth-code', $state );

$connection = GoogleConnection::firstWhere( 'user_id', $user->id );

expect( $connection )->not->toBeNull();
expect( $connection->email )->toBe( 'you@example.com' );
expect( $connection->access_token )->toBe( 'initial-access-token' );
```

## Testing the token manager

Same pattern — fake the token endpoint and assert the manager returns the fresh token:

```php
use ArtisanPackUI\Google\Facades\Google;

Http::fake( [
    'https://oauth2.googleapis.com/token' => Http::response( [
        'access_token' => 'refreshed',
        'expires_in'   => 3600,
        'token_type'   => 'Bearer',
    ] ),
] );

$connection = GoogleConnection::factory()->create( [
    'access_token'  => 'expired-token',
    'refresh_token' => 'a-refresh-token',
    'expires_at'    => now()->subMinute(),
    'status'        => 'connected',
] );

expect( Google::tokens()->getValidAccessToken( $connection ) )->toBe( 'refreshed' );
expect( $connection->fresh()->access_token )->toBe( 'refreshed' );
```

Test the `invalid_grant` disconnect:

```php
Http::fake( [
    'https://oauth2.googleapis.com/token' => Http::response( [
        'error' => 'invalid_grant',
    ], 400 ),
] );

expect( fn () => Google::tokens()->refresh( $connection ) )
    ->toThrow( TokenRefreshException::class );

expect( $connection->fresh()->status )->toBe( 'disconnected' );
expect( $connection->fresh()->disconnect_reason )->toBe( 'Refresh token revoked or expired.' );
```

## Testing scope contributions

Register a scope inside the test and assert `all()` picks it up:

```php
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.google.scopes', function ( array $scopes ): array {
    $scopes[] = 'https://www.googleapis.com/auth/analytics.readonly';
    return $scopes;
} );

expect( Google::scopes()->all() )->toContain( 'https://www.googleapis.com/auth/analytics.readonly' );
```

Filter registrations persist across tests within the same process — call `Filter::remove()` in `tearDown()` or use a fresh filter registry per test if you need isolation.

## Testing configuration drivers

Switch drivers in the test's `setUp()`:

```php
config( [ 'google.driver' => 'database' ] );

Google::config()->save( [
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'redirect_uri'  => 'https://tests.test/google/auth/callback',
] );

expect( Google::config()->isConfigured() )->toBeTrue();
expect( Google::config()->getClientSecret() )->toBe( 'test-client-secret' );
```

Because `ConfigurationRepository` is `bind()`ed (not `singleton()`), the container re-reads `config('google.driver')` on each resolve — you can flip the driver mid-test without container-flushing.

## Testing controllers

```php
$user = User::factory()->create();

$this->actingAs( $user )
    ->get( route( 'google.auth.connect' ) )
    ->assertRedirectContains( 'https://accounts.google.com/o/oauth2/v2/auth' );
```

For the callback, prime the session first:

```php
session( [
    'google.oauth.state'    => 'test-state',
    'google.oauth.verifier' => 'test-verifier',
    'google.oauth.user_id'  => $user->id,
] );

Http::fake( [ 'https://oauth2.googleapis.com/token' => Http::response( [ /* ... */ ] ) ] );

$this->actingAs( $user )
    ->get( route( 'google.auth.callback' ) . '?code=test-code&state=test-state' )
    ->assertRedirect( '/' );

$this->assertDatabaseHas( 'google_connections', [
    'user_id' => $user->id,
    'status'  => 'connected',
] );
```

## Testing the Livewire component

```php
use ArtisanPackUI\Google\Livewire\ConnectionManager;
use Livewire\Livewire;

Livewire::actingAs( $user )
    ->test( ConnectionManager::class )
    ->assertSee( 'Connect Google' );

// Seed a connection and re-test:
GoogleConnection::factory()->for( $user )->create( [ 'email' => 'you@example.com' ] );

Livewire::actingAs( $user )
    ->test( ConnectionManager::class )
    ->assertSee( 'you@example.com' );
```

## Testing the status endpoint

```php
$response = $this->actingAs( $user )->getJson( '/google/auth/status' );

$response->assertOk()->assertJson( [
    'connected' => false,
    'email'     => null,
] );
```

## Testing the JS components

See [[Connection UI/React#Testing]] and [[Connection UI/Vue#Testing]].

## Running the package's own tests

From the package directory:

```bash
composer test          # runs Pest
composer lint          # php-cs-fixer --dry-run + phpcs
composer fix           # php-cs-fixer fix
```

From this dev app (with the package symlinked):

```bash
php artisan test --compact
```
