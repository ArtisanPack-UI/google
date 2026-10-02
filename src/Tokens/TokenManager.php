<?php

/**
 * OAuth2 token manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Tokens;

use ArtisanPackUI\Google\Broker\BrokerClient;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\Exceptions\LicenseExpiredException;
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\OAuth\GoogleClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Handles token refresh and returns valid access tokens.
 *
 * Callers should always use `getValidAccessToken()` before making
 * Google API calls; the manager checks expiry and refreshes
 * transparently. On refresh failure (Google returned invalid_grant
 * or the request errored) the connection is marked disconnected.
 *
 * @since 1.0.0
 */
class TokenManager
{
    public function __construct(
        protected ConfigurationRepository $config,
        protected ConfigRepository $laravelConfig,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Return a valid access token, refreshing if the current one is expired.
     *
     * @since 1.0.0
     *
     * @throws TokenRefreshException When the connection cannot be refreshed.
     */
    public function getValidAccessToken( GoogleConnection $connection ): string
    {
        if ( ! $connection->isConnected() ) {
            throw new TokenRefreshException( __( 'Google connection is disconnected.' ) );
        }

        if ( ! $connection->isExpired() && ! empty( $connection->access_token ) ) {
            return (string) $connection->access_token;
        }

        return $this->refresh( $connection );
    }

    /**
     * Force a refresh regardless of expiry.
     *
     * Goes to Google directly, or through the OAuth broker when
     * `google.mode` is `broker`.
     *
     * @since 1.0.0
     *
     * @throws LicenseExpiredException When the broker reports the site license has lapsed. The connection stays connected.
     * @throws TokenRefreshException   For any other failure. A revoked grant also marks the connection disconnected.
     */
    public function refresh( GoogleConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        try {
            $tokens = BrokerClient::isEnabled( $this->laravelConfig )
                ? $this->brokerClient()->refresh( (string) $connection->refresh_token )
                : GoogleClient::make( $this->config, $this->http, $this->laravelConfig )
                    ->refresh( (string) $connection->refresh_token );
        } catch ( TokenRefreshException $e ) {
            // Only a revoked grant disconnects. A lapsed broker license
            // (LicenseExpiredException) leaves the connection intact so
            // refreshes resume as soon as the license is renewed.
            if ( 'invalid_grant' === $e->getError() ) {
                $connection->markDisconnected( __( 'Refresh token revoked or expired.' ) );
            }

            throw $e;
        }

        $connection->access_token  = $tokens->accessToken;
        $connection->token_type    = $tokens->tokenType;
        $connection->refresh_token = $tokens->refreshToken;

        if ( null !== $tokens->expiresAt ) {
            $connection->expires_at = $tokens->expiresAt;
        }

        if ( [] !== $tokens->scopes ) {
            $connection->scopes = $tokens->scopes;
        }

        $connection->save();

        return (string) $connection->access_token;
    }

    /**
     * Broker client built from the configured broker credentials.
     *
     * @since 1.2.0
     *
     * @throws TokenRefreshException When the broker is not configured.
     */
    protected function brokerClient(): BrokerClient
    {
        try {
            return BrokerClient::fromConfig( $this->laravelConfig, $this->http );
        } catch ( OAuthException $e ) {
            throw new TokenRefreshException( $e->getMessage(), 'broker_not_configured', null, $e );
        }
    }
}
