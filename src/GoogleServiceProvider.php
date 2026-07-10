<?php

/**
 * Google service provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google;

use ArtisanPackUI\Google\Configuration\ConfigDriver;
use ArtisanPackUI\Google\Configuration\DatabaseDriver;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\OAuth\OAuthManager;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use ArtisanPackUI\Google\Tokens\TokenManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Google package.
 *
 * @since 1.0.0
 */
class GoogleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom( __DIR__ . '/../config/google.php', 'google' );

        // Bind (not singleton) so `config('google.driver')` is re-read on each
        // resolve; the concrete driver classes are singletons in their own
        // right and hold the per-request cache.
        $this->app->singleton( ConfigDriver::class, fn ( Application $app ): ConfigDriver => new ConfigDriver( $app[ 'config' ] ) );

        $this->app->singleton( DatabaseDriver::class, fn ( Application $app ): DatabaseDriver => new DatabaseDriver( $app[ 'db' ]->connection(), $app[ 'encrypter' ] ) );

        $this->app->bind( ConfigurationRepository::class, function ( Application $app ): ConfigurationRepository {
            $driver = $app[ 'config' ]->get( 'google.driver', 'config' );

            return 'database' === $driver
                ? $app->make( DatabaseDriver::class )
                : $app->make( ConfigDriver::class );
        } );

        $this->app->singleton( ScopeRegistry::class );

        $this->app->singleton( TokenManager::class, function ( Application $app ): TokenManager {
            return new TokenManager(
                $app->make( ConfigurationRepository::class ),
                $app[ 'config' ],
                $app->make( HttpFactory::class ),
            );
        } );

        $this->app->singleton( OAuthManager::class, function ( Application $app ): OAuthManager {
            return new OAuthManager(
                $app->make( ConfigurationRepository::class ),
                $app[ 'config' ],
                $app[ 'session.store' ],
                $app->make( HttpFactory::class ),
                $app->make( ScopeRegistry::class ),
            );
        } );

        $this->app->singleton( 'google', function ( Application $app ): Google {
            return new Google(
                $app->make( ConfigurationRepository::class ),
                $app->make( ScopeRegistry::class ),
                $app->make( TokenManager::class ),
                $app->make( OAuthManager::class ),
            );
        } );
    }

    public function boot(): void
    {
        $this->publishes( [
            __DIR__ . '/../config/google.php' => config_path( 'google.php' ),
        ], 'google-config' );

        $this->publishes( [
            __DIR__ . '/../database/migrations' => database_path( 'migrations' ),
        ], 'google-migrations' );

        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        $config = $this->app[ 'config' ]->get( 'google.routes', [] );

        if ( false === ( $config[ 'enabled' ] ?? true ) ) {
            return;
        }

        Route::group( [
            'prefix'     => $config[ 'prefix' ] ?? 'google/auth',
            'middleware' => $config[ 'middleware' ] ?? [ 'web' ],
        ], function (): void {
            $this->loadRoutesFrom( __DIR__ . '/../routes/web.php' );
        } );
    }
}
