<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Models\GoogleConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'isExpired() does not mutate the stored expires_at attribute', function (): void {
    $connection = GoogleConnection::create( [
        'user_id'    => 1,
        'expires_at' => now()->addHour(),
        'status'     => GoogleConnection::STATUS_CONNECTED,
    ] );

    // Snapshot AFTER create so we compare against the cast-normalized value.
    $baseline = $connection->expires_at->copy();

    // Call isExpired repeatedly; each call must not shift the attribute.
    $connection->isExpired();
    $connection->isExpired();
    $connection->isExpired();

    expect( $connection->expires_at->equalTo( $baseline ) )->toBeTrue();
} );

it( 'rejects mass-assignment of columns outside the fillable allowlist', function (): void {
    $connection = new GoogleConnection();
    $connection->fill( [
        'user_id'          => 1,
        'access_token'     => 'a',
        'not_a_real_field' => 'ignored',
    ] );

    expect( $connection->getAttribute( 'not_a_real_field' ) )->toBeNull();
    expect( $connection->access_token )->toBe( 'a' );
} );
