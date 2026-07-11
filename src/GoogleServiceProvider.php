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

use ArtisanPackUI\Google\Configuration\CmsSettingsDriver;
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

        $this->app->singleton( CmsSettingsDriver::class, fn ( Application $app ): CmsSettingsDriver => new CmsSettingsDriver( $app[ 'encrypter' ] ) );

        $this->app->bind( ConfigurationRepository::class, function ( Application $app ): ConfigurationRepository {
            $driver = $app[ 'config' ]->get( 'google.driver', 'config' );

            return match ( $driver ) {
                'database' => $app->make( DatabaseDriver::class ),
                'cms'      => $app->make( CmsSettingsDriver::class ),
                default    => $app->make( ConfigDriver::class ),
            };
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

        $this->loadViewsFrom( __DIR__ . '/../resources/views', 'google' );

        $this->publishes( [
            __DIR__ . '/../resources/views' => resource_path( 'views/vendor/google' ),
        ], 'google-views' );

        $this->publishes( [
            __DIR__ . '/../resources/js' => resource_path( 'js/vendor/google' ),
        ], 'google-js' );

        $this->registerRoutes();
        $this->registerCmsSettings();
        $this->registerLivewireComponents();
    }

    /**
     * Register OAuth-credential settings with the CMS framework when it is
     * installed. No-op otherwise — the base package must not hard-depend on
     * the CMS framework.
     *
     * Runs inside `$this->app->booted()` because the CMS-framework helpers
     * (apRegisterSetting / apGetSetting / apUpdateSetting) are declared from
     * that package's own boot() method, and Laravel's provider boot order is
     * not deterministic. If GoogleServiceProvider happens to boot first,
     * registering directly from this class's boot() would silently skip the
     * three keys and the CMS Settings UI would never expose them.
     *
     * @since 1.0.0
     */
    protected function registerCmsSettings(): void
    {
        $this->app->booted( function (): void {
            if ( ! function_exists( 'apRegisterSetting' ) ) {
                return;
            }

            $encrypter = $this->app[ 'encrypter' ];

            $trim = static function ( mixed $value ): ?string {
                if ( null === $value || '' === $value ) {
                    return null;
                }

                return trim( (string) $value );
            };

            // The client secret is written to the CMS Settings row by two
            // paths: `Google::config()->save()` (driver → apUpdateSetting)
            // and the CMS Settings UI (operator → apUpdateSetting directly).
            // Owning encryption inside the sanitize callback makes both
            // paths write ciphertext, so the read-side decryption always
            // sees an encrypted value.
            $encryptSecret = static function ( mixed $value ) use ( $encrypter, $trim ): ?string {
                $trimmed = $trim( $value );

                if ( null === $trimmed ) {
                    return null;
                }

                return $encrypter->encryptString( $trimmed );
            };

            apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_ID, null, $trim );
            apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, null, $encryptSecret );
            apRegisterSetting( CmsSettingsDriver::KEY_REDIRECT_URI, null, $trim );
        } );
    }

    /**
     * Register the connection-management Livewire component when Livewire is
     * installed. Livewire is an optional peer — apps without it can build
     * their own UI on top of the same controller endpoints.
     *
     * @since 1.0.0
     */
    protected function registerLivewireComponents(): void
    {
        if ( ! class_exists( \Livewire\Livewire::class ) ) {
            return;
        }

        \Livewire\Livewire::component(
            'google-connection-manager',
            Livewire\ConnectionManager::class,
        );
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
