<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use ArtisanPackUI\Hooks\Facades\Filter;

it( 'includes baseline identity scopes by default', function (): void {
    $registry = app( ScopeRegistry::class );

    expect( $registry->all() )->toContain( 'openid' );
    expect( $registry->all() )->toContain( 'https://www.googleapis.com/auth/userinfo.email' );
} );

it( 'unions scopes contributed via the ap.google.scopes filter', function (): void {
    Filter::add( 'ap.google.scopes', function ( array $scopes ): array {
        $scopes[] = 'https://www.googleapis.com/auth/analytics.readonly';
        $scopes[] = 'https://www.googleapis.com/auth/webmasters.readonly';
        return $scopes;
    } );

    $registry = app( ScopeRegistry::class );
    $all      = $registry->all();

    expect( $all )->toContain( 'https://www.googleapis.com/auth/analytics.readonly' );
    expect( $all )->toContain( 'https://www.googleapis.com/auth/webmasters.readonly' );
} );

it( 'deduplicates scopes from multiple sources', function (): void {
    Filter::add( 'ap.google.scopes', fn ( array $s ) => array_merge( $s, [ 'openid', 'x' ] ) );

    $registry = app( ScopeRegistry::class );
    $registry->register( 'x' );

    $all = $registry->all();
    expect( array_count_values( $all )[ 'openid' ] )->toBe( 1 );
    expect( array_count_values( $all )[ 'x' ] )->toBe( 1 );
} );

it( 'computes missing scopes vs granted', function (): void {
    Filter::add( 'ap.google.scopes', fn ( array $s ) => array_merge( $s, [ 'a', 'b', 'c' ] ) );

    $registry = app( ScopeRegistry::class );

    expect( $registry->missing( [ 'openid', 'a' ] ) )->toContain( 'b' );
    expect( $registry->missing( [ 'openid', 'a' ] ) )->toContain( 'c' );
    expect( $registry->missing( $registry->all() ) )->toBe( [] );
    expect( $registry->hasAllRequired( $registry->all() ) )->toBeTrue();
    expect( $registry->hasAllRequired( [] ) )->toBeFalse();
} );

it( 'ignores non-array filter returns gracefully', function (): void {
    Filter::add( 'ap.google.scopes', fn () => 'not-an-array' );

    $registry = app( ScopeRegistry::class );

    expect( $registry->all() )->toContain( 'openid' );
} );
