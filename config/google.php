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
    | "database", "cms". The "cms" driver is only available when
    | `artisanpack-ui/cms-framework` is installed and stores credentials via
    | its Settings module. OAuth tokens are always stored in the database
    | regardless of this setting.
    |
    */
    'driver' => env( 'GOOGLE_CONFIG_DRIVER', 'config' ),

    /*
    |--------------------------------------------------------------------------
    | OAuth Mode
    |--------------------------------------------------------------------------
    |
    | "direct" (default) talks to Google with this app's own client ID and
    | secret. "broker" runs connect, callback and refresh through an OAuth
    | broker instead, so the site never holds a Google client secret — only
    | the broker settings below.
    |
    */
    'mode' => env( 'GOOGLE_OAUTH_MODE', 'direct' ),

    /*
    |--------------------------------------------------------------------------
    | OAuth Broker
    |--------------------------------------------------------------------------
    |
    | Used when `mode` is "broker". `return_url` defaults to the package's
    | callback route and must be on the URL the site registered with the
    | broker. Hosts can supply url / site_id / site_secret at runtime via the
    | `ap.google.broker.credentials` filter instead.
    |
    */
    'broker' => [
        'url'         => env( 'GOOGLE_BROKER_URL' ),
        'site_id'     => env( 'GOOGLE_BROKER_SITE_ID' ),
        'site_secret' => env( 'GOOGLE_BROKER_SITE_SECRET' ),
        'return_url'  => env( 'GOOGLE_BROKER_RETURN_URL' ),
    ],

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
