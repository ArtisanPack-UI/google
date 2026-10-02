<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\OAuth\GoogleClient;
use ArtisanPackUI\Google\OAuth\GoogleCredentials;
use ArtisanPackUI\Google\OAuth\TokenResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->credentials = new GoogleCredentials( 'runtime-cid', 'runtime-secret', 'https://broker.test/cb' );
} );

it( 'builds a consent URL from runtime credentials and the caller state without touching the session', function (): void {
    $url = Google::client( $this->credentials )->authorizationUrl( 'caller-state', [ 'openid', 'email' ] );

    expect( $url )->toStartWith( 'https://accounts.google.com/o/oauth2/v2/auth?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['client_id'] )->toBe( 'runtime-cid' );
    expect( $params['redirect_uri'] )->toBe( 'https://broker.test/cb' );
    expect( $params['state'] )->toBe( 'caller-state' );
    expect( $params['scope'] )->toBe( 'openid email' );
    expect( $params['access_type'] )->toBe( 'offline' );
    expect( $params['prompt'] )->toBe( 'consent' );
    expect( $params['include_granted_scopes'] )->toBe( 'true' );
    expect( $params )->not->toHaveKey( 'code_challenge' );

    expect( session()->all() )->toBe( [] );
} );

it( 'adds PKCE only when the caller supplies a verifier', function (): void {
    $verifier = GoogleClient::generateCodeVerifier();

    $url = Google::client( $this->credentials )->authorizationUrl( 's', [ 'openid' ], [], $verifier );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['code_challenge'] )->toBe( GoogleClient::codeChallenge( $verifier ) );
    expect( $params['code_challenge_method'] )->toBe( 'S256' );
} );

it( 'lets extra parameters override the defaults', function (): void {
    $url = Google::client( $this->credentials )->authorizationUrl( 's', [ 'openid' ], [
        'prompt'     => 'select_account',
        'login_hint' => 'user@example.com',
    ] );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['prompt'] )->toBe( 'select_account' );
    expect( $params['login_hint'] )->toBe( 'user@example.com' );
    expect( $params['access_type'] )->toBe( 'offline' );
} );

it( 'refuses to build a consent URL without a client ID or redirect URI', function (): void {
    $client = Google::client( new GoogleCredentials( 'cid', 'secret' ) );

    expect( fn () => $client->authorizationUrl( 's', [ 'openid' ] ) )->toThrow( OAuthException::class );
} );

it( 'falls back to the configured credential driver', function (): void {
    config()->set( 'google.client_id', 'configured-cid' );
    config()->set( 'google.client_secret', 'configured-secret' );
    config()->set( 'google.redirect_uri', 'https://app.test/cb' );

    $credentials = Google::client()->credentials();

    expect( $credentials->clientId )->toBe( 'configured-cid' );
    expect( $credentials->clientSecret )->toBe( 'configured-secret' );
    expect( $credentials->redirectUri )->toBe( 'https://app.test/cb' );
} );

it( 'exchanges a code and returns tokens without persisting them', function (): void {
    $idPayload = json_encode( [ 'sub' => '1082', 'email' => 'user@example.com', 'name' => 'Jane Doe' ] );
    $idToken   = 'HEADER.' . rtrim( strtr( base64_encode( $idPayload ), '+/', '-_' ), '=' ) . '.SIG';

    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token'  => 'a1',
            'refresh_token' => 'r1',
            'expires_in'    => 3600,
            'token_type'    => 'Bearer',
            'scope'         => 'openid https://www.googleapis.com/auth/userinfo.email',
            'id_token'      => $idToken,
        ] ),
    ] );

    $tokens = Google::client( $this->credentials )->exchangeCode( 'the-code' );

    expect( $tokens )->toBeInstanceOf( TokenResponse::class );
    expect( $tokens->accessToken )->toBe( 'a1' );
    expect( $tokens->refreshToken )->toBe( 'r1' );
    expect( $tokens->expiresIn )->toBe( 3600 );
    expect( $tokens->expiresAt?->isFuture() )->toBeTrue();
    expect( $tokens->scopes )->toBe( [ 'openid', 'https://www.googleapis.com/auth/userinfo.email' ] );
    expect( $tokens->idToken )->toBe( $idToken );
    expect( $tokens->accountId )->toBe( '1082' );
    expect( $tokens->accountEmail )->toBe( 'user@example.com' );
    expect( $tokens->accountName )->toBe( 'Jane Doe' );

    expect( GoogleConnection::query()->count() )->toBe( 0 );

    Http::assertSent( function ( $request ): bool {
        $data = $request->data();

        return 'runtime-cid' === $data['client_id']
            && 'runtime-secret' === $data['client_secret']
            && 'https://broker.test/cb' === $data['redirect_uri']
            && ! array_key_exists( 'code_verifier', $data );
    } );
} );

it( 'sends the code verifier on exchange when one is given', function (): void {
    Http::fake( [ 'oauth2.googleapis.com/*' => Http::response( [ 'access_token' => 'a1' ] ) ] );

    Google::client( $this->credentials )->exchangeCode( 'c', 'the-verifier' );

    Http::assertSent( fn ( $request ): bool => 'the-verifier' === $request->data()['code_verifier'] );
} );

it( 'carries the Google error code on a failed exchange', function (): void {
    Http::fake( [ 'oauth2.googleapis.com/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    try {
        Google::client( $this->credentials )->exchangeCode( 'bad' );
        $this->fail( 'Expected an OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
    }
} );

it( 'treats a successful response without an access token as a failed exchange', function (): void {
    Http::fake( [ 'oauth2.googleapis.com/*' => Http::response( [ 'token_type' => 'Bearer' ] ) ] );

    expect( fn () => Google::client( $this->credentials )->exchangeCode( 'c' ) )
        ->toThrow( OAuthException::class );
} );

it( 'refreshes a raw refresh token and keeps it when Google does not rotate it', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [ 'access_token' => 'fresh', 'expires_in' => 3599 ] ),
    ] );

    $tokens = Google::client( $this->credentials )->refresh( 'old-refresh' );

    expect( $tokens->accessToken )->toBe( 'fresh' );
    expect( $tokens->refreshToken )->toBe( 'old-refresh' );
    expect( $tokens->scopes )->toBe( [] );

    Http::assertSent( fn ( $request ): bool => 'refresh_token' === $request->data()['grant_type']
        && 'old-refresh' === $request->data()['refresh_token'] );
} );

it( 'returns the rotated refresh token when Google issues a new one', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [ 'access_token' => 'fresh', 'refresh_token' => 'new-refresh' ] ),
    ] );

    expect( Google::client( $this->credentials )->refresh( 'old-refresh' )->refreshToken )->toBe( 'new-refresh' );
} );

it( 'carries invalid_grant on a revoked refresh token', function (): void {
    Http::fake( [ 'oauth2.googleapis.com/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    try {
        Google::client( $this->credentials )->refresh( 'revoked' );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
    }
} );

it( 'renders the broker wire shape', function (): void {
    $tokens = TokenResponse::fromGoogle( [
        'access_token' => 'a1',
        'expires_in'   => 60,
        'scope'        => 'openid',
    ] );

    expect( $tokens->toArray() )->toBe( [
        'token_type'    => 'Bearer',
        'access_token'  => 'a1',
        'refresh_token' => null,
        'expires_in'    => 60,
        'scopes'        => [ 'openid' ],
        'account_email' => null,
        'account_name'  => null,
        'id_token'      => null,
    ] );
} );
