<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Exceptions\LicenseExpiredException;
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\OAuth\OAuthManager;
use ArtisanPackUI\Google\Tokens\TokenManager;
use ArtisanPackUI\Hooks\Facades\Filter;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    // A broker-mode site holds no Google client credentials at all.
    config()->set( 'google.client_id', null );
    config()->set( 'google.client_secret', null );
    config()->set( 'google.redirect_uri', null );

    config()->set( 'google.mode', 'broker' );
    config()->set( 'google.broker.url', 'https://workshop.test' );
    config()->set( 'google.broker.site_id', 'site-123' );
    config()->set( 'google.broker.site_secret', '7|plain-site-secret' );
} );

function brokerTokenPayload( array $overrides = [] ): array
{
    return $overrides + [
        'token_type'    => 'Bearer',
        'access_token'  => 'broker-access',
        'refresh_token' => 'broker-refresh',
        'expires_in'    => 3600,
        'scopes'        => [ 'openid', 'https://www.googleapis.com/auth/userinfo.email' ],
        'account_email' => 'user@example.com',
        'account_name'  => 'Jane Doe',
        'id_token'      => null,
    ];
}

it( 'reports broker mode on the facade', function (): void {
    expect( Google::usesBroker() )->toBeTrue();

    config()->set( 'google.mode', 'direct' );

    expect( Google::usesBroker() )->toBeFalse();
} );

it( 'sends the user to the broker instead of Google, without a PKCE verifier', function (): void {
    $url = app( OAuthManager::class )->authorizationUrl( 42 );

    expect( $url )->toStartWith( 'https://workshop.test/api/v1/oauth/google/authorize?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['state'] )->toBe( session( 'google.oauth.state' ) );
    expect( $params['return_url'] )->toBe( route( 'google.auth.callback' ) );
    expect( $params['scopes'] )->toContain( 'openid' );
    expect( session( 'google.oauth.user_id' ) )->toBe( 42 );
    expect( session( 'google.oauth.verifier' ) )->toBeNull();
} );

it( 'uses the configured return URL when set', function (): void {
    config()->set( 'google.broker.return_url', 'https://site.test/custom-callback' );

    parse_str( parse_url( app( OAuthManager::class )->authorizationUrl( 1 ), PHP_URL_QUERY ), $params );

    expect( $params['return_url'] )->toBe( 'https://site.test/custom-callback' );
} );

it( 'refuses to build a broker link when the broker is not configured', function (): void {
    config()->set( 'google.broker.site_secret', null );

    expect( fn () => app( OAuthManager::class )->authorizationUrl( 1 ) )->toThrow( OAuthException::class );
} );

it( 'passes only the missing scopes to the broker on incremental consent', function (): void {
    Filter::add( 'ap.google.scopes', fn ( array $scopes ): array => [ ...$scopes, 'https://www.googleapis.com/auth/webmasters.readonly' ] );

    $url = app( OAuthManager::class )->reauthorizationUrl( 42, [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ] );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['scopes'] )->toBe( 'https://www.googleapis.com/auth/webmasters.readonly' );
} );

it( 'exchanges the broker code and stores the connection exactly as in direct mode', function (): void {
    Http::fake( [ 'workshop.test/api/v1/oauth/token' => Http::response( brokerTokenPayload() ) ] );

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    $connection = $oauth->handleCallback( 'broker-code', session( 'google.oauth.state' ) );

    expect( $connection->user_id )->toBe( 42 );
    expect( $connection->access_token )->toBe( 'broker-access' );
    expect( $connection->refresh_token )->toBe( 'broker-refresh' );
    expect( $connection->email )->toBe( 'user@example.com' );
    expect( $connection->scopes )->toContain( 'openid' );
    expect( $connection->isConnected() )->toBeTrue();

    Http::assertNotSent( fn ( $request ): bool => str_contains( $request->url(), 'googleapis.com' ) );

    // Service packages keep reading tokens the same way.
    expect( Google::tokens()->getValidAccessToken( $connection ) )->toBe( 'broker-access' );
} );

it( 'keeps the existing scopes when the broker reports none', function (): void {
    GoogleConnection::create( [
        'user_id'       => 42,
        'refresh_token' => 'r-old',
        'scopes'        => [ 'openid' ],
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    Http::fake( [ 'workshop.test/*' => Http::response( brokerTokenPayload( [ 'scopes' => [] ] ) ) ] );

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    expect( $oauth->handleCallback( 'c', session( 'google.oauth.state' ) )->scopes )->toBe( [ 'openid' ] );
} );

it( 'stores no scopes for a new connection when the broker reports none', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( brokerTokenPayload( [ 'scopes' => [] ] ) ) ] );

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    $connection = $oauth->handleCallback( 'c', session( 'google.oauth.state' ) );

    expect( $connection->scopes )->toBe( [] );
    expect( Google::scopes()->hasAllRequired( $connection->grantedScopes() ) )->toBeFalse();
} );

it( 'still rejects a mismatched state in broker mode', function (): void {
    Http::fake();

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    expect( fn () => $oauth->handleCallback( 'broker-code', 'tampered' ) )->toThrow( OAuthException::class );

    Http::assertNothingSent();
} );

it( 'refreshes through the broker without a client secret', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/refresh' => Http::response( brokerTokenPayload( [
            'access_token'  => 'refreshed',
            'refresh_token' => null,
        ] ) ),
    ] );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'stale',
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    expect( app( TokenManager::class )->getValidAccessToken( $connection ) )->toBe( 'refreshed' );
    expect( $connection->fresh()->refresh_token )->toBe( 'r1' );
} );

it( 'keeps the connection connected when the license has expired', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'error'     => 'license_expired',
            'renew_url' => 'https://workshop.test/renew',
        ], 402 ),
    ] );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    try {
        app( TokenManager::class )->refresh( $connection );
        $this->fail( 'Expected a LicenseExpiredException.' );
    } catch ( LicenseExpiredException $e ) {
        expect( $e->getRenewUrl() )->toBe( 'https://workshop.test/renew' );
    }

    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'disconnects on a revoked grant reported by the broker', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'refresh_token' => 'revoked',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
    expect( $connection->fresh()->isConnected() )->toBeFalse();
} );

it( 'keeps the connection on a transient broker failure', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'temporarily_unavailable' ], 503 ) ] );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'raises a refresh exception when the broker is not configured', function (): void {
    config()->set( 'google.broker.url', null );

    $connection = GoogleConnection::create( [
        'user_id'       => 1,
        'refresh_token' => 'r1',
        'expires_at'    => now()->subMinute(),
        'status'        => GoogleConnection::STATUS_CONNECTED,
    ] );

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
} );

describe( 'routes', function (): void {
    beforeEach( function (): void {
        Schema::create( 'users', function ( $t ): void {
            $t->id();
            $t->timestamps();
        } );

        $this->user = new class extends AuthUser {
            protected $table = 'users';

            protected $guarded = [];
        };
        $this->user->save();
    } );

    it( 'redirects connect to the broker', function (): void {
        $response = $this->actingAs( $this->user )->get( '/google/auth/connect' );

        expect( $response->headers->get( 'Location' ) )
            ->toStartWith( 'https://workshop.test/api/v1/oauth/google/authorize?' );
    } );

    it( 'completes the callback through the broker', function (): void {
        Http::fake( [ 'workshop.test/*' => Http::response( brokerTokenPayload() ) ] );

        $this->actingAs( $this->user )->get( '/google/auth/connect' );

        $this->actingAs( $this->user )
            ->get( '/google/auth/callback?code=broker-code&state=' . session( 'google.oauth.state' ) )
            ->assertSessionHas( 'google.status', 'connected' );

        expect( GoogleConnection::query()->first()->access_token )->toBe( 'broker-access' );
    } );

    it( 'flashes a license_expired error with a trusted renew URL', function (): void {
        $this->get( '/google/auth/callback?error=license_expired&state=s&renew_url=' . urlencode( 'https://workshop.test/renew' ) )
            ->assertSessionHas( 'google.error', 'license_expired' )
            ->assertSessionHas( 'google.renew_url', 'https://workshop.test/renew' );
    } );

    it( 'drops a renew URL that does not point at the broker', function (): void {
        $this->get( '/google/auth/callback?error=license_expired&state=s&renew_url=' . urlencode( 'https://evil.test/renew' ) )
            ->assertSessionHas( 'google.error', 'license_expired' )
            ->assertSessionMissing( 'google.renew_url' );
    } );

    it( 'still redirects with the error when the broker URL is insecure', function (): void {
        config()->set( 'google.broker.url', 'http://workshop.example.com' );

        $this->get( '/google/auth/callback?error=license_expired&renew_url=' . urlencode( 'http://workshop.example.com/renew' ) )
            ->assertRedirect()
            ->assertSessionHas( 'google.error', 'license_expired' )
            ->assertSessionMissing( 'google.renew_url' );
    } );

    it( 'ignores renew URLs outside broker mode', function (): void {
        config()->set( 'google.mode', 'direct' );

        $this->get( '/google/auth/callback?error=license_expired&renew_url=' . urlencode( 'https://workshop.test/renew' ) )
            ->assertSessionMissing( 'google.renew_url' );
    } );
} );
