<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Models\GoogleConnection;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'google.client_id', 'cid' );
    config()->set( 'google.client_secret', 'csecret' );
    config()->set( 'google.redirect_uri', 'https://example.test/cb' );

    Schema::create( 'users', function ( $t ): void {
        $t->id();
        $t->string( 'email' )->nullable();
        $t->timestamps();
    } );
} );

it( 'redirects to Google on connect for an authenticated user', function (): void {
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    $response = $this->actingAs( $user )->get( '/google/auth/connect' );

    $response->assertRedirect();
    expect( $response->headers->get( 'Location' ) )
        ->toStartWith( 'https://accounts.google.com/o/oauth2/v2/auth?' );
} );

it( 'aborts with 401 for a guest', function (): void {
    $this->get( '/google/auth/connect' )->assertStatus( 401 );
} );

it( 'exchanges the code on the callback route', function (): void {
    Http::fake( [
        'oauth2.googleapis.com/*' => Http::response( [
            'access_token'  => 'a1',
            'refresh_token' => 'r1',
            'expires_in'    => 3600,
        ] ),
    ] );

    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    // Prime the session by hitting connect first.
    $this->actingAs( $user )->get( '/google/auth/connect' );
    $state = session( 'google.oauth.state' );

    $response = $this->actingAs( $user )->get( '/google/auth/callback?code=abc&state=' . $state );

    $response->assertRedirect();
    expect( GoogleConnection::query()->count() )->toBe( 1 );
    expect( GoogleConnection::query()->first()->access_token )->toBe( 'a1' );
} );

it( 'redirects with an error flash when the callback carries an error', function (): void {
    $response = $this->get( '/google/auth/callback?error=access_denied' );

    $response->assertRedirect();
    $response->assertSessionHas( 'google.error', 'access_denied' );
} );

it( 'honors google.routes.redirect_after_connect and redirect_after_error config', function (): void {
    config()->set( 'google.routes.redirect_after_connect', '/dashboard' );
    config()->set( 'google.routes.redirect_after_error', '/settings/integrations' );

    $response = $this->get( '/google/auth/callback?error=access_denied' );
    $response->assertRedirect( '/settings/integrations' );
} );
