<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\OAuth\OAuthManager;
use ArtisanPackUI\Hooks\Facades\Filter;

beforeEach( function (): void {
    config()->set( 'google.client_id', 'cid' );
    config()->set( 'google.client_secret', 'csecret' );
    config()->set( 'google.redirect_uri', 'https://example.test/cb' );
} );

it( 'requests only the missing scopes on reauthorization', function (): void {
    Filter::add( 'ap.google.scopes', fn ( array $s ) => array_merge( $s, [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
    ] ) );

    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );

    $granted = [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    $url = $oauth->reauthorizationUrl( 42, $granted );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params[ 'include_granted_scopes' ] )->toBe( 'true' );
    expect( $params[ 'scope' ] )->toBe( 'https://www.googleapis.com/auth/webmasters.readonly' );
} );

it( 'falls back to the full union when no scopes are missing', function (): void {
    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );

    $granted = [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ];

    $url = $oauth->reauthorizationUrl( 42, $granted );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params[ 'scope' ] )->toContain( 'openid' );
    expect( $params[ 'scope' ] )->toContain( 'userinfo.email' );
} );

it( 'still stashes state, verifier, and user_id on reauthorization', function (): void {
    /** @var OAuthManager $oauth */
    $oauth = app( OAuthManager::class );

    $oauth->reauthorizationUrl( 42, [ 'openid' ] );

    expect( session( 'google.oauth.state' ) )->not->toBeEmpty();
    expect( session( 'google.oauth.verifier' ) )->not->toBeEmpty();
    expect( session( 'google.oauth.user_id' ) )->toBe( 42 );
} );
