<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Livewire\ConnectionManager;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Hooks\Facades\Filter;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Schema::create( 'users', function ( $t ): void {
        $t->id();
        $t->string( 'email' )->nullable();
        $t->timestamps();
    } );
} );

function make_test_user(): AuthUser
{
    $user = new class extends AuthUser {
        protected $table = 'users';

        protected $guarded = [];
    };
    $user->save();

    return $user;
}

it( 'shows the connect button when no connection exists', function (): void {
    Livewire::actingAs( make_test_user() )
        ->test( ConnectionManager::class )
        ->assertSee( 'Connect Google' )
        ->assertDontSee( 'Disconnect' );
} );

it( 'shows the connected email and disconnect action when connected', function (): void {
    $user = make_test_user();

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

    Livewire::actingAs( $user )
        ->test( ConnectionManager::class )
        ->assertSee( 'Connected as' )
        ->assertSee( 'jane@example.com' )
        ->assertSee( 'Disconnect' );
} );

it( 'surfaces a reauthorize prompt when the scope registry grows', function (): void {
    Filter::add(
        'ap.google.scopes',
        fn ( array $s ) => array_merge( $s, [ 'https://www.googleapis.com/auth/webmasters.readonly' ] ),
    );

    $user = make_test_user();

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

    Livewire::actingAs( $user )
        ->test( ConnectionManager::class )
        ->assertSee( 'reauthorization' )
        ->assertSee( 'Reauthorize' );
} );
