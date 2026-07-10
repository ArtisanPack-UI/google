<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get( '/connect', [ GoogleAuthController::class, 'connect' ] )
    ->name( 'google.auth.connect' );

Route::get( '/callback', [ GoogleAuthController::class, 'callback' ] )
    ->name( 'google.auth.callback' );

Route::get( '/reauthorize', [ GoogleAuthController::class, 'reauthorize' ] )
    ->name( 'google.auth.reauthorize' );

Route::post( '/disconnect', [ GoogleAuthController::class, 'disconnect' ] )
    ->name( 'google.auth.disconnect' );

Route::get( '/status', [ GoogleAuthController::class, 'status' ] )
    ->name( 'google.auth.status' );
