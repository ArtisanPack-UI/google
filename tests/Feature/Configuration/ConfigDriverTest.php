<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Configuration\ConfigDriver;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;

it( 'is the default driver bound to the contract', function (): void {
    expect( app( ConfigurationRepository::class ) )->toBeInstanceOf( ConfigDriver::class );
} );

it( 'reads credentials from the config repository', function (): void {
    config()->set( 'google.client_id', 'test-client-id' );
    config()->set( 'google.client_secret', 'test-secret' );
    config()->set( 'google.redirect_uri', 'https://example.test/callback' );

    /** @var ConfigDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBe( 'test-client-id' );
    expect( $driver->getClientSecret() )->toBe( 'test-secret' );
    expect( $driver->getRedirectUri() )->toBe( 'https://example.test/callback' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'reports unconfigured when any value is missing', function (): void {
    config()->set( 'google.client_id', null );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeFalse();
} );

it( 'throws when save is called on the read-only config driver', function (): void {
    $driver = app( ConfigurationRepository::class );

    expect( fn () => $driver->save( [ 'client_id' => 'x' ] ) )
        ->toThrow( RuntimeException::class );
} );
