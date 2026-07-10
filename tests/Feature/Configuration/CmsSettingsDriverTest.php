<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Configuration\CmsSettingsDriver;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;

beforeEach( function (): void {
    // Reset the stub-backed CMS settings store between tests. Sanitize
    // callbacks were registered when the Google service provider booted
    // via `$this->app->booted()` (see tests/Support/CmsSettingsStub.php).
    $GLOBALS[ '__cms_settings_stub_values' ] = [];

    config()->set( 'google.driver', 'cms' );
    app( CmsSettingsDriver::class )->flush();
} );

it( 'resolves the CmsSettingsDriver when driver=cms', function (): void {
    expect( app( ConfigurationRepository::class ) )
        ->toBeInstanceOf( CmsSettingsDriver::class );
} );

it( 'writes credentials through apUpdateSetting and reads them back', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid-from-cms',
        'client_secret' => 'super-secret',
        'redirect_uri'  => 'https://example.test/cb',
    ] );

    $driver->flush();

    expect( $driver->getClientId() )->toBe( 'cid-from-cms' );
    expect( $driver->getClientSecret() )->toBe( 'super-secret' );
    expect( $driver->getRedirectUri() )->toBe( 'https://example.test/cb' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'stores the client secret encrypted at rest', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'client_secret' => 'plaintext-secret',
        'redirect_uri'  => 'https://example.test/cb',
    ] );

    $raw = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ];

    expect( $raw )->not->toBe( 'plaintext-secret' );
    expect( app( 'encrypter' )->decryptString( $raw ) )->toBe( 'plaintext-secret' );
} );

it( 'treats a null or missing secret as unconfigured', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'client_secret' => null,
        'redirect_uri'  => 'https://example.test/cb',
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'encrypts secrets written directly through apUpdateSetting (Settings UI path)', function (): void {
    // Simulates an operator typing the client secret into the CMS
    // Settings admin UI: apUpdateSetting is called with plaintext. The
    // sanitize callback registered by GoogleServiceProvider must encrypt
    // it, otherwise the driver's decryption step later blows up and
    // isConfigured() flips to false with no visible reason.
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_ID, 'ui-cid' );
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, 'ui-typed-secret' );
    apUpdateSetting( CmsSettingsDriver::KEY_REDIRECT_URI, 'https://example.test/cb' );

    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );
    $driver->flush();

    $raw = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ];
    expect( $raw )->not->toBe( 'ui-typed-secret' );
    expect( app( 'encrypter' )->decryptString( $raw ) )->toBe( 'ui-typed-secret' );

    expect( $driver->getClientSecret() )->toBe( 'ui-typed-secret' );
    expect( $driver->isConfigured() )->toBeTrue();
} );
