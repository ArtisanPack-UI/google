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

it( 'redirects to Google on reauthorize with only the missing scopes', function (): void {
    ArtisanPackUI\Hooks\Facades\Filter::add(
        'ap.google.scopes',
        fn ( array $s ) => array_merge( $s, [ 'https://www.googleapis.com/auth/webmasters.readonly' ] ),
    );

    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    GoogleConnection::create( [
        'user_id'        => $user->id,
        'google_user_id' => '1',
        'email'          => 'jane@example.com',
        'access_token'   => 'a',
        'refresh_token'  => 'r',
        'token_type'     => 'Bearer',
        'scopes'         => [
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
        'status'         => GoogleConnection::STATUS_CONNECTED,
    ] );

    $response = $this->actingAs( $user )->get( '/google/auth/reauthorize' );

    $response->assertRedirect();
    $location = $response->headers->get( 'Location' );
    expect( $location )->toStartWith( 'https://accounts.google.com/o/oauth2/v2/auth?' );

    parse_str( parse_url( $location, PHP_URL_QUERY ), $params );
    expect( $params[ 'scope' ] )->toBe( 'https://www.googleapis.com/auth/webmasters.readonly' );
} );

it( 'marks the connection disconnected on POST /disconnect', function (): void {
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    GoogleConnection::create( [
        'user_id'        => $user->id,
        'google_user_id' => '1',
        'email'          => 'jane@example.com',
        'access_token'   => 'a',
        'refresh_token'  => 'r',
        'token_type'     => 'Bearer',
        'scopes'         => [ 'openid' ],
        'status'         => GoogleConnection::STATUS_CONNECTED,
    ] );

    $response = $this->actingAs( $user )
        ->from( '/settings' )
        ->post( '/google/auth/disconnect' );

    $response->assertRedirect();

    $connection = GoogleConnection::query()->where( 'user_id', $user->id )->first();
    expect( $connection->status )->toBe( GoogleConnection::STATUS_DISCONNECTED );
    expect( $connection->disconnect_reason )->not->toBeNull();
} );

it( 'returns disconnected status JSON for an unconnected user', function (): void {
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    $response = $this->actingAs( $user )->getJson( '/google/auth/status' );

    $response->assertOk();
    $response->assertJson( [
        'connected'        => false,
        'email'            => null,
        'needsReauthorize' => false,
    ] );
    $json = $response->json();
    expect( $json[ 'requiredScopes' ] )->toContain( 'openid' );
    expect( $json[ 'urls' ] )->toHaveKeys( [ 'connect', 'reauthorize', 'disconnect' ] );
} );

it( 'returns connected status JSON with missing scopes flagged', function (): void {
    ArtisanPackUI\Hooks\Facades\Filter::add(
        'ap.google.scopes',
        fn ( array $s ) => array_merge( $s, [ 'https://www.googleapis.com/auth/webmasters.readonly' ] ),
    );

    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    GoogleConnection::create( [
        'user_id'        => $user->id,
        'google_user_id' => '1',
        'email'          => 'jane@example.com',
        'access_token'   => 'a',
        'refresh_token'  => 'r',
        'token_type'     => 'Bearer',
        'scopes'         => [
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
        'status'         => GoogleConnection::STATUS_CONNECTED,
    ] );

    $response = $this->actingAs( $user )->getJson( '/google/auth/status' );

    $response->assertOk();
    $response->assertJson( [
        'connected'        => true,
        'email'            => 'jane@example.com',
        'needsReauthorize' => true,
    ] );
    expect( $response->json( 'missingScopes' ) )
        ->toBe( [ 'https://www.googleapis.com/auth/webmasters.readonly' ] );
} );

it( 'rejects the status endpoint for guests', function (): void {
    $this->getJson( '/google/auth/status' )->assertUnauthorized();
} );

it( 'masks email and granted scopes on the status endpoint for a disconnected connection', function (): void {
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    GoogleConnection::create( [
        'user_id'        => $user->id,
        'google_user_id' => '1',
        'email'          => 'jane@example.com',
        'access_token'   => 'a',
        'refresh_token'  => 'r',
        'token_type'     => 'Bearer',
        'scopes'         => [ 'openid' ],
        'status'         => GoogleConnection::STATUS_DISCONNECTED,
    ] );

    $response = $this->actingAs( $user )->getJson( '/google/auth/status' );

    $response->assertOk();
    $response->assertJson( [
        'connected'     => false,
        'email'         => null,
        'grantedScopes' => [],
    ] );
} );

it( 'redirects reauthorize to connect when the user has no connection', function (): void {
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    $response = $this->actingAs( $user )->get( '/google/auth/reauthorize' );

    $response->assertRedirect( route( 'google.auth.connect' ) );
} );

it( 'redirects reauthorize to connect when the connection is disconnected', function (): void {
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    GoogleConnection::create( [
        'user_id'        => $user->id,
        'google_user_id' => '1',
        'email'          => 'jane@example.com',
        'access_token'   => 'a',
        'refresh_token'  => 'r',
        'token_type'     => 'Bearer',
        'scopes'         => [ 'openid' ],
        'status'         => GoogleConnection::STATUS_DISCONNECTED,
    ] );

    $response = $this->actingAs( $user )->get( '/google/auth/reauthorize' );

    $response->assertRedirect( route( 'google.auth.connect' ) );
} );

it( 'honors google.routes.redirect_after_connect and redirect_after_error config', function (): void {
    config()->set( 'google.routes.redirect_after_connect', '/dashboard' );
    config()->set( 'google.routes.redirect_after_error', '/settings/integrations' );

    $response = $this->get( '/google/auth/callback?error=access_denied' );
    $response->assertRedirect( '/settings/integrations' );
} );
