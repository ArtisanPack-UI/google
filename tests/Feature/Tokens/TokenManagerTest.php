<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\Tokens\TokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'google.client_id', 'cid' );
    config()->set( 'google.client_secret', 'csecret' );
    config()->set( 'google.redirect_uri', 'https://example.test/cb' );
} );

it( 'returns the stored token when not expired', function (): void {
    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'still-good',
        'refresh_token' => 'r1',
        'expires_at'    => now()->addHour(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    Http::fake();

    $token = app( TokenManager::class )->getValidAccessToken( $connection );

    expect( $token )->toBe( 'still-good' );
    Http::assertNothingSent();
} );

it( 'refreshes an expired token transparently', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token' => 'fresh',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
            'scope'        => 'openid https://www.googleapis.com/auth/userinfo.email',
        ] ),
    ] );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'stale',
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    $token = app( TokenManager::class )->getValidAccessToken( $connection );

    expect( $token )->toBe( 'fresh' );
    expect( $connection->fresh()->access_token )->toBe( 'fresh' );
    expect( $connection->fresh()->scopes )->toContain( 'openid' );
} );

it( 'marks the connection disconnected on invalid_grant', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response(
            [ 'error' => 'invalid_grant' ],
            400,
        ),
    ] );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'refresh_token' => 'revoked',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    expect( fn () => app( TokenManager::class )->getValidAccessToken( $connection ) )
        ->toThrow( TokenRefreshException::class );

    expect( $connection->fresh()->status )->toBe( GoogleConnection::STATUS_DISCONNECTED );
} );

it( 'refuses to use a disconnected connection', function (): void {
    $connection = GoogleConnection::create( [
        'user_id' => 1,
        'status'  => GoogleConnection::STATUS_DISCONNECTED,
    ] );

    expect( fn () => app( TokenManager::class )->getValidAccessToken( $connection ) )
        ->toThrow( TokenRefreshException::class );
} );

it( 'refuses to refresh with no stored refresh token', function (): void {
    $connection = GoogleConnection::create( [
        'user_id'      => 1,
        'access_token' => 'stale',
        'expires_at'   => now()->subMinute(),
        'status'       => GoogleConnection::STATUS_CONNECTED,
    ] );

    expect( fn () => app( TokenManager::class )->getValidAccessToken( $connection ) )
        ->toThrow( TokenRefreshException::class );

    expect( $connection->fresh()->status )->toBe( GoogleConnection::STATUS_DISCONNECTED );
} );

it( 'encrypts tokens at rest', function (): void {
    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'plaintext-access',
        'refresh_token' => 'plaintext-refresh',
        'expires_at'    => now()->addHour(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    $raw = DB::table( 'google_connections' )->where( 'id', $connection->id )->first();

    expect( $raw->access_token )->not->toBe( 'plaintext-access' );
    expect( $raw->refresh_token )->not->toBe( 'plaintext-refresh' );
    expect( $connection->fresh()->access_token )->toBe( 'plaintext-access' );
} );

it( 'reuses a token another process refreshed while this one waited for the lock', function (): void {
    Http::fake();

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'stale',
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    // Another process refreshed the row after this instance was loaded.
    GoogleConnection::findOrFail( $connection->getKey() )->update( [
        'access_token' => 'refreshed-elsewhere',
        'expires_at'   => now()->addHour(),
    ] );

    expect( app( TokenManager::class )->getValidAccessToken( $connection ) )->toBe( 'refreshed-elsewhere' );
    Http::assertNothingSent();
} );

it( 'gives up without disconnecting when another refresh holds the lock', function (): void {
    Http::fake();

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'stale',
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    $lock = Cache::lock( 'artisanpack-ui:google:refresh:' . $connection->getKey(), 30 );
    $lock->get();

    $manager = new class( app( ArtisanPackUI\Google\Contracts\ConfigurationRepository::class ), config(), app( Illuminate\Http\Client\Factory::class ) ) extends TokenManager {
        protected const LOCK_WAIT_SECONDS = 0;
    };

    try {
        $manager->refresh( $connection );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'refresh_in_progress' );
    } finally {
        $lock->release();
    }

    expect( $connection->fresh()->isConnected() )->toBeTrue();
    Http::assertNothingSent();
} );
