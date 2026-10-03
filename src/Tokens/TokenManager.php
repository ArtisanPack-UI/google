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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Handles token refresh and returns valid access tokens.
 *
 * Callers should always use `getValidAccessToken()` before making
 * Google API calls; the manager checks expiry and refreshes
 * transparently. Only a revoked grant (`invalid_grant`) marks the
 * connection disconnected; other failures leave it as it is.
 *
 * Refreshes of one connection are serialized with a cache lock, and a
 * refresh that finds another process already refreshed the connection
 * reuses that token instead of spending the refresh token again.
 *
 * @since 1.0.0
 */
class TokenManager
{
    /**
     * The broker's error for a refresh token another request rotated moments
     * ago. The connection holds (or will hold) the rotated token, so it is
     * never treated as a revoked grant.
     *
     * @since 1.4.0
     */
    public const REFRESH_SUPERSEDED = 'refresh_superseded';

    /**
     * How long a refresh may hold the lock, in seconds.
     *
     * @since 1.4.0
     */
    protected const LOCK_SECONDS = 30;

    /**
     * How long a refresh waits for another one on the same connection, in seconds.
     *
     * @since 1.4.0
     */
    protected const LOCK_WAIT_SECONDS = 10;

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
     * `google.mode` is `broker`. Refreshes of the same connection wait on a
     * cache lock; if another process replaced the access token in the
     * meantime, its token is returned without a second refresh.
     *
     * @since 1.0.0
     * @since 1.4.0 Serialized per connection, and tolerant of the broker's `refresh_superseded`.
     *
     * @throws LicenseExpiredException When the broker reports the site license has lapsed. The connection stays connected.
     * @throws TokenRefreshException   For any other failure. A revoked grant also marks the connection disconnected; `refresh_in_progress` means another refresh held the lock too long.
     */
    public function refresh( GoogleConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $staleAccessToken = $connection->access_token;

        try {
            return Cache::lock( $this->lockKey( $connection ), static::LOCK_SECONDS )->block(
                static::LOCK_WAIT_SECONDS,
                function () use ( $connection, $staleAccessToken ): string {
                    if ( $connection->exists ) {
                        $connection->refresh();
                    }

                    return $this->tokenRefreshedElsewhere( $connection, $staleAccessToken )
                        ?? $this->requestRefresh( $connection, $staleAccessToken );
                },
            );
        } catch ( LockTimeoutException $e ) {
            throw new TokenRefreshException(
                __( 'Another refresh of this Google connection is still running.' ),
                'refresh_in_progress',
                null,
                $e,
            );
        }
    }

    /**
     * Refresh the connection's tokens with Google or the broker and save them.
     *
     * @since 1.4.0
     *
     * @param  ?string  $staleAccessToken  The access token the caller wanted replaced.
     *
     * @throws TokenRefreshException
     */
    protected function requestRefresh( GoogleConnection $connection, ?string $staleAccessToken ): string
    {
        try {
            $tokens = BrokerClient::isEnabled( $this->laravelConfig )
                ? $this->brokerClient()->refresh( (string) $connection->refresh_token )
                : GoogleClient::make( $this->config, $this->http, $this->laravelConfig )
                    ->refresh( (string) $connection->refresh_token );
        } catch ( TokenRefreshException $e ) {
            // Another request rotated the refresh token first. Use the token it
            // saved if there is one; never disconnect for it.
            if ( self::REFRESH_SUPERSEDED === $e->getError() ) {
                if ( $connection->exists ) {
                    $connection->refresh();
                }

                return $this->tokenRefreshedElsewhere( $connection, $staleAccessToken ) ?? throw $e;
            }

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
     * The connection's access token, when another process has replaced the
     * stale one with a token that is still valid.
     *
     * @since 1.4.0
     *
     * @param  ?string  $staleAccessToken  The access token the caller wanted replaced.
     */
    protected function tokenRefreshedElsewhere( GoogleConnection $connection, ?string $staleAccessToken ): ?string
    {
        if (
            $connection->isConnected()
            && ! empty( $connection->access_token )
            && $connection->access_token !== $staleAccessToken
            && ! $connection->isExpired()
        ) {
            return (string) $connection->access_token;
        }

        return null;
    }

    /**
     * The cache lock key that serializes refreshes of one connection.
     *
     * @since 1.4.0
     */
    protected function lockKey( GoogleConnection $connection ): string
    {
        return 'artisanpack-ui:google:refresh:' . ( $connection->getKey() ?? spl_object_id( $connection ) );
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
