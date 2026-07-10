<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get( '/connect', [ GoogleAuthController::class, 'connect' ] )
    ->name( 'google.auth.connect' );

Route::get( '/callback', [ GoogleAuthController::class, 'callback' ] )
    ->name( 'google.auth.callback' );
