<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Configuration\DatabaseDriver;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'google.driver', 'database' );
} );

it( 'uses the database driver when configured', function (): void {
    expect( app( ConfigurationRepository::class ) )->toBeInstanceOf( DatabaseDriver::class );
} );

it( 'persists and reads credentials with an encrypted secret', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-1',
        'client_secret' => 'super-secret',
        'redirect_uri'  => 'https://example.test/callback',
    ] );

    expect( $driver->getClientId() )->toBe( 'app-1' );
    expect( $driver->getClientSecret() )->toBe( 'super-secret' );
    expect( $driver->getRedirectUri() )->toBe( 'https://example.test/callback' );

    $stored = DB::table( 'google_configurations' )->first();
    expect( $stored->client_secret )->not->toBe( 'super-secret' );
} );

it( 'updates the existing row on subsequent saves', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-1',
        'client_secret' => 's1',
        'redirect_uri'  => 'https://a.test/cb',
    ] );

    // Fresh instance to bypass the driver's per-request cache.
    app()->forgetInstance( DatabaseDriver::class );
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-2',
        'client_secret' => 's2',
        'redirect_uri'  => 'https://b.test/cb',
    ] );

    expect( DB::table( 'google_configurations' )->count() )->toBe( 1 );
    expect( $driver->getClientId() )->toBe( 'app-2' );
} );
