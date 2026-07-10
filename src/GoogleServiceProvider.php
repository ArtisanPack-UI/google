<?php

/**
 * Google service provider.
 *
 * Bootstraps the shared Google package by registering the container
 * binding for the main class. Adjacent Google service packages
 * (analytics-google, google-search-console, google-tag-manager)
 * depend on this provider for OAuth2, token, and scope services.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Google package.
 *
 * Binds the main Google class and boots the shared OAuth2 surface.
 * Add configuration publishing, migrations, and routes here as the
 * package grows.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */
class GoogleServiceProvider extends ServiceProvider
{
    /**
     * Registers any application services.
     *
     * Binds the Google class as a singleton in the container so the
     * `google()` helper and Google facade resolve to a shared instance.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton( 'google', function ( $app ) {
            return new Google();
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * Add package bootstrapping here such as:
     * - Configuration publishing: $this->publishes([...])
     * - Migration loading: $this->loadMigrationsFrom(...)
     * - Route loading: $this->loadRoutesFrom(...)
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        // Add your package bootstrapping here
    }
}
