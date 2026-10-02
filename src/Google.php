<?php

/**
 * Main Google class.
 *
 * Entry point for the shared Google OAuth2 authentication, token
 * storage/refresh, and scope management surface that powers the
 * ArtisanPack UI Google service integrations. Accessed via the
 * `google()` helper function or the Google facade.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google;

use ArtisanPackUI\Google\Broker\BrokerClient;
use ArtisanPackUI\Google\Broker\BrokerCredentials;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\OAuth\GoogleClient;
use ArtisanPackUI\Google\OAuth\GoogleCredentials;
use ArtisanPackUI\Google\OAuth\OAuthManager;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use ArtisanPackUI\Google\Tokens\TokenManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Convenience aggregator for the Google package services.
 *
 * Provides quick access to the configuration repository, scope
 * registry, token manager, and OAuth manager. Also serves as the
 * target of the `Google` facade and `google()` helper.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */
class Google
{
    public function __construct(
        protected ConfigurationRepository $config,
        protected ScopeRegistry $scopes,
        protected TokenManager $tokens,
        protected OAuthManager $oauth,
        protected HttpFactory $http,
        protected ConfigRepository $laravelConfig,
    ) {
    }

    public function config(): ConfigurationRepository
    {
        return $this->config;
    }

    public function scopes(): ScopeRegistry
    {
        return $this->scopes;
    }

    public function tokens(): TokenManager
    {
        return $this->tokens;
    }

    public function oauth(): OAuthManager
    {
        return $this->oauth;
    }

    /**
     * A stateless Google OAuth client.
     *
     * With no arguments it uses the configured credential driver. Pass
     * explicit credentials to relay for another app, as an OAuth broker
     * does; the client never touches the session or the database.
     *
     * @since 1.2.0
     */
    public function client( ?GoogleCredentials $credentials = null ): GoogleClient
    {
        return GoogleClient::make( $credentials ?? $this->config, $this->http, $this->laravelConfig );
    }

    /**
     * A client for the OAuth broker, from explicit or configured credentials.
     *
     * @since 1.2.0
     *
     * @throws Exceptions\OAuthException When no credentials are passed and none are configured.
     */
    public function broker( ?BrokerCredentials $credentials = null ): BrokerClient
    {
        if ( null !== $credentials ) {
            return new BrokerClient( $credentials, $this->http );
        }

        return BrokerClient::fromConfig( $this->laravelConfig, $this->http );
    }

    /**
     * Whether the package is in broker client mode (`google.mode` = `broker`).
     *
     * @since 1.2.0
     */
    public function usesBroker(): bool
    {
        return BrokerClient::isEnabled( $this->laravelConfig );
    }
}
