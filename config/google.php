<?php

/**
 * Google package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | App Credentials
    |--------------------------------------------------------------------------
    |
    | Google OAuth2 application credentials used by the default config driver.
    | When using the database configuration driver, these values are ignored
    | and read from the database instead.
    |
    */
    'client_id'     => env( 'GOOGLE_CLIENT_ID' ),
    'client_secret' => env( 'GOOGLE_CLIENT_SECRET' ),
    'redirect_uri'  => env( 'GOOGLE_REDIRECT_URI' ),

    /*
    |--------------------------------------------------------------------------
    | Configuration Driver
    |--------------------------------------------------------------------------
    |
    | Which driver backs the ConfigurationRepository. Supported: "config",
    | "database". OAuth tokens are always stored in the database regardless
    | of this setting.
    |
    */
    'driver' => env( 'GOOGLE_CONFIG_DRIVER', 'config' ),

    /*
    |--------------------------------------------------------------------------
    | OAuth Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token'     => 'https://oauth2.googleapis.com/token',
        'revoke'    => 'https://oauth2.googleapis.com/revoke',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled'    => true,
        'prefix'     => 'google/auth',
        'middleware' => [ 'web' ],

        /*
        | Where to send the user after a successful connect or an OAuth
        | error. Either a path ('/settings/integrations') or a named
        | route ('settings.integrations') — the latter is preferred.
        */
        'redirect_after_connect' => '/',
        'redirect_after_error'   => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The user model that GoogleConnection belongs to.
    |
    */
    'user_model' => env( 'GOOGLE_USER_MODEL', 'App\\Models\\User' ),
];
