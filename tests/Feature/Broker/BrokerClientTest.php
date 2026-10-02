<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Broker\BrokerClient;
use ArtisanPackUI\Google\Broker\BrokerCredentials;
use ArtisanPackUI\Google\Exceptions\LicenseExpiredException;
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Hooks\Facades\Filter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    $this->credentials = new BrokerCredentials( 'https://workshop.test', 'site-123', '7|plain-site-secret' );
    $this->broker      = Google::broker( $this->credentials );
} );

afterEach( function (): void {
    Carbon::setTestNow();
} );

it( 'builds a signed authorize link that matches the broker contract', function (): void {
    Carbon::setTestNow( '2026-10-02 12:00:00' );

    $url = $this->broker->authorizationUrl( 'site-state', 'https://site.test/google/auth/callback', [ 'openid', 'email' ] );

    expect( $url )->toStartWith( 'https://workshop.test/api/v1/oauth/google/authorize?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['site_id'] )->toBe( 'site-123' );
    expect( $params['state'] )->toBe( 'site-state' );
    expect( $params['return_url'] )->toBe( 'https://site.test/google/auth/callback' );
    expect( $params['scopes'] )->toBe( 'openid email' );
    expect( (int) $params['expires'] )->toBe( Carbon::now()->getTimestamp() + 300 );

    // Recompute the signature exactly as the contract describes it.
    $unsigned = $params;
    unset( $unsigned['signature'] );
    ksort( $unsigned );
    $expected = hash_hmac(
        'sha256',
        "google\n" . http_build_query( $unsigned, '', '&', PHP_QUERY_RFC3986 ),
        hash( 'sha256', 'plain-site-secret' ),
    );

    expect( $params['signature'] )->toBe( $expected );
} );

it( 'omits scopes so the broker grants every allowed scope', function (): void {
    $url = $this->broker->authorizationUrl( 's', 'https://site.test/cb' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params )->not->toHaveKey( 'scopes' );
} );

it( 'keys signatures with the whole secret when it has no id prefix', function (): void {
    $credentials = new BrokerCredentials( 'https://workshop.test', 'site-123', 'no-pipe-secret' );

    expect( $credentials->signingKey() )->toBe( hash( 'sha256', 'no-pipe-secret' ) );
} );

it( 'exchanges the one-time code at the broker with the site secret as bearer', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/token' => Http::response( [
            'token_type'    => 'Bearer',
            'access_token'  => 'a1',
            'refresh_token' => 'r1',
            'expires_in'    => 3600,
            'scopes'        => [ 'openid', 'email' ],
            'account_email' => 'user@example.com',
            'account_name'  => 'Jane Doe',
            'id_token'      => null,
        ] ),
    ] );

    $tokens = $this->broker->exchangeCode( 'broker-code' );

    expect( $tokens->accessToken )->toBe( 'a1' );
    expect( $tokens->refreshToken )->toBe( 'r1' );
    expect( $tokens->scopes )->toBe( [ 'openid', 'email' ] );
    expect( $tokens->accountEmail )->toBe( 'user@example.com' );
    expect( $tokens->accountName )->toBe( 'Jane Doe' );
    expect( $tokens->idToken )->toBeNull();

    Http::assertSent( fn ( $request ): bool => 'https://workshop.test/api/v1/oauth/token' === $request->url()
        && 'Bearer 7|plain-site-secret' === $request->header( 'Authorization' )[0]
        && 'authorization_code' === $request->data()['grant_type']
        && 'broker-code' === $request->data()['code'] );
} );

it( 'accepts nullable fields in the broker response', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'token_type'    => 'Bearer',
            'access_token'  => 'a1',
            'refresh_token' => null,
            'expires_in'    => null,
            'scopes'        => [],
            'account_email' => null,
            'account_name'  => null,
            'id_token'      => null,
        ] ),
    ] );

    $tokens = $this->broker->exchangeCode( 'c' );

    expect( $tokens->refreshToken )->toBeNull();
    expect( $tokens->expiresAt )->toBeNull();
} );

it( 'surfaces broker exchange errors', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    try {
        $this->broker->exchangeCode( 'used-code' );
        $this->fail( 'Expected an OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
    }
} );

it( 'refreshes through the broker and keeps the old refresh token when none is returned', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/refresh' => Http::response( [
            'token_type'   => 'Bearer',
            'access_token' => 'fresh',
            'expires_in'   => 3600,
            'scopes'       => [ 'openid' ],
        ] ),
    ] );

    $tokens = $this->broker->refresh( 'r-old' );

    expect( $tokens->accessToken )->toBe( 'fresh' );
    expect( $tokens->refreshToken )->toBe( 'r-old' );

    Http::assertSent( fn ( $request ): bool => 'r-old' === $request->data()['refresh_token']
        && 'google' === $request->data()['provider']
        && ! array_key_exists( 'client_secret', $request->data() ) );
} );

it( 'raises a distinct exception carrying renew_url when the license has expired', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'error'     => 'license_expired',
            'renew_url' => 'https://workshop.test/renew/site-123',
        ], 402 ),
    ] );

    try {
        $this->broker->refresh( 'r1' );
        $this->fail( 'Expected a LicenseExpiredException.' );
    } catch ( LicenseExpiredException $e ) {
        expect( $e->getError() )->toBe( 'license_expired' );
        expect( $e->getRenewUrl() )->toBe( 'https://workshop.test/renew/site-123' );
    }
} );

it( 'reports broker refresh errors with their OAuth code', function ( int $status, string $error ): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => $error ], $status ) ] );

    try {
        $this->broker->refresh( 'r1' );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( LicenseExpiredException $e ) {
        $this->fail( 'Only a 402 should raise LicenseExpiredException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( $error );
    }
} )->with( [
    'revoked grant'        => [ 400, 'invalid_grant' ],
    'broker unavailable'   => [ 503, 'temporarily_unavailable' ],
    'provider unavailable' => [ 502, 'provider_unavailable' ],
] );

it( 'only trusts renew URLs on the broker host', function (): void {
    expect( $this->broker->isTrustedRenewUrl( 'https://workshop.test/renew' ) )->toBeTrue();
    expect( $this->broker->isTrustedRenewUrl( 'https://evil.test/renew' ) )->toBeFalse();
    expect( $this->broker->isTrustedRenewUrl( 'https://workshop.test.evil.test/renew' ) )->toBeFalse();
    expect( $this->broker->isTrustedRenewUrl( 'javascript:alert(1)' ) )->toBeFalse();
    expect( $this->broker->isTrustedRenewUrl( '' ) )->toBeFalse();
} );

it( 'rejects an HTTP renew URL for an HTTPS broker but allows it for a local HTTP broker', function (): void {
    expect( $this->broker->isTrustedRenewUrl( 'http://workshop.test/renew' ) )->toBeFalse();

    $local = Google::broker( new BrokerCredentials( 'http://workshop.test', 'site-123', '7|secret' ) );

    expect( $local->isTrustedRenewUrl( 'http://workshop.test/renew' ) )->toBeTrue();
} );

it( 'treats a non-string access token as a failed exchange', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'token_type' => 'Bearer', 'access_token' => [ 'x' ], 'scopes' => [] ] ) ] );

    expect( fn () => $this->broker->exchangeCode( 'c' ) )->toThrow( OAuthException::class );
} );

it( 'resolves credentials from config and lets hosts override them via a filter', function (): void {
    config()->set( 'google.broker.url', 'https://workshop.test/' );
    config()->set( 'google.broker.site_id', 'from-config' );
    config()->set( 'google.broker.site_secret', '1|secret' );

    $credentials = BrokerCredentials::fromConfig( config() );

    expect( $credentials?->url )->toBe( 'https://workshop.test' );
    expect( $credentials?->siteId )->toBe( 'from-config' );

    Filter::add( 'ap.google.broker.credentials', fn ( array $values ): array => [ 'site_id' => 'from-cms' ] + $values );

    expect( BrokerCredentials::fromConfig( config() )?->siteId )->toBe( 'from-cms' );
} );

it( 'refuses to build a client from incomplete broker config', function (): void {
    config()->set( 'google.broker.url', 'https://workshop.test' );

    expect( BrokerCredentials::fromConfig( config() ) )->toBeNull();
    expect( fn () => BrokerClient::fromConfig( config(), app( Illuminate\Http\Client\Factory::class ) ) )
        ->toThrow( OAuthException::class );
} );

it( 'refuses to send the site secret to a plain-HTTP broker outside local development', function ( string $url ): void {
    expect( fn () => new BrokerCredentials( $url, 'site-123', '7|secret' ) )->toThrow( OAuthException::class );
} )->with( [
    'public http host'    => 'http://workshop.example.com',
    'public http ip'      => 'http://203.0.113.10',
    'non-http scheme'     => 'ftp://workshop.test',
    'missing host'        => 'https:///api',
    'test lookalike host' => 'http://workshop.test.example.com',
] );

it( 'accepts HTTPS and local development broker URLs', function ( string $url ): void {
    expect( ( new BrokerCredentials( $url, 'site-123', '7|secret' ) )->url )->toBe( $url );
} )->with( [
    'https'           => 'https://workshop.example.com',
    'localhost'       => 'http://localhost:8000',
    'localhost alias' => 'http://workshop.localhost',
    'herd .test'      => 'http://workshop.test',
    'ipv4 loopback'   => 'http://127.0.0.1:8000',
    'ipv6 loopback'   => 'http://[::1]:8000',
] );

it( 'rejects an insecure broker URL from config', function (): void {
    config()->set( 'google.broker.url', 'http://workshop.example.com' );
    config()->set( 'google.broker.site_id', 'site-123' );
    config()->set( 'google.broker.site_secret', '7|secret' );

    expect( fn () => BrokerCredentials::fromConfig( config() ) )->toThrow( OAuthException::class );
} );
