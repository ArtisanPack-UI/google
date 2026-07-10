<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\OAuth\OAuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'google.client_id', 'cid' );
    config()->set( 'google.client_secret', 'csecret' );
    config()->set( 'google.redirect_uri', 'https://example.test/cb' );
} );

it( 'builds an authorization URL with offline access, prompt=consent, and PKCE', function (): void {
    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );

    $url = $oauth->authorizationUrl( 42 );

    expect( $url )->toStartWith( 'https://accounts.google.com/o/oauth2/v2/auth?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params[ 'client_id' ] )->toBe( 'cid' );
    expect( $params[ 'redirect_uri' ] )->toBe( 'https://example.test/cb' );
    expect( $params[ 'response_type' ] )->toBe( 'code' );
    expect( $params[ 'access_type' ] )->toBe( 'offline' );
    expect( $params[ 'prompt' ] )->toBe( 'consent' );
    expect( $params[ 'code_challenge_method' ] )->toBe( 'S256' );
    expect( $params[ 'code_challenge' ] )->not->toBeEmpty();
    expect( $params[ 'state' ] )->not->toBeEmpty();
    expect( $params[ 'scope' ] )->toContain( 'openid' );

    expect( session( 'google.oauth.state' ) )->toBe( $params[ 'state' ] );
    expect( session( 'google.oauth.user_id' ) )->toBe( 42 );
    expect( session( 'google.oauth.verifier' ) )->not->toBeEmpty();
} );

it( 'refuses to build a URL without configured credentials', function (): void {
    config()->set( 'google.client_id', null );

    expect( fn () => app( OAuthManager::class )->authorizationUrl( 1 ) )
        ->toThrow( OAuthException::class );
} );

it( 'exchanges the code and persists a connected connection on callback', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token'  => 'a1',
            'refresh_token' => 'r1',
            'expires_in'    => 3600,
            'token_type'    => 'Bearer',
            'scope'         => 'openid https://www.googleapis.com/auth/userinfo.email',
        ] ),
    ] );

    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );
    $state = session( 'google.oauth.state' );

    $connection = $oauth->handleCallback( 'the-code', $state );

    expect( $connection )->toBeInstanceOf( GoogleConnection::class );
    expect( $connection->user_id )->toBe( 42 );
    expect( $connection->access_token )->toBe( 'a1' );
    expect( $connection->refresh_token )->toBe( 'r1' );
    expect( $connection->status )->toBe( GoogleConnection::STATUS_CONNECTED );
    expect( $connection->scopes )->toContain( 'openid' );
} );

it( 'rejects a mismatched state', function (): void {
    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    expect( fn () => $oauth->handleCallback( 'the-code', 'tampered-state' ) )
        ->toThrow( OAuthException::class );
} );

it( 'surfaces exchange errors from Google', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response(
            [ 'error' => 'invalid_grant' ],
            400,
        ),
    ] );

    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    expect( fn () => $oauth->handleCallback( 'bad-code', session( 'google.oauth.state' ) ) )
        ->toThrow( OAuthException::class );
} );

it( 'extracts google_user_id and email from the returned id_token', function (): void {
    $idPayload = json_encode( [ 'sub' => '108234567890123456789', 'email' => 'user@example.com' ] );
    $idToken   = 'HEADER.' . rtrim( strtr( base64_encode( $idPayload ), '+/', '-_' ), '=' ) . '.SIG';

    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token' => 'a1',
            'expires_in'   => 3600,
            'id_token'     => $idToken,
        ] ),
    ] );

    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    $connection = $oauth->handleCallback( 'c', session( 'google.oauth.state' ) );

    expect( $connection->google_user_id )->toBe( '108234567890123456789' );
    expect( $connection->email )->toBe( 'user@example.com' );
} );

it( 'tolerates a missing or malformed id_token by leaving identity columns null', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token' => 'a1',
            'expires_in'   => 3600,
            'id_token'     => 'not.a.valid.jwt.at.all',
        ] ),
    ] );

    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    $connection = $oauth->handleCallback( 'c', session( 'google.oauth.state' ) );

    expect( $connection->google_user_id )->toBeNull();
    expect( $connection->email )->toBeNull();
} );

it( 'sends PKCE code_verifier when exchanging the code', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token' => 'a1',
            'expires_in'   => 3600,
        ] ),
    ] );

    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );
    $verifier = session( 'google.oauth.verifier' );

    $oauth->handleCallback( 'c', session( 'google.oauth.state' ) );

    Http::assertSent( function ( $request ) use ( $verifier ): bool {
        return $request->data()[ 'code_verifier' ] === $verifier
            && 'authorization_code' === $request->data()[ 'grant_type' ];
    } );
} );
