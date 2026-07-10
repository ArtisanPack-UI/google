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

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Models\GoogleConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

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
     * @since 1.0.0
     *
     * @throws TokenRefreshException
     */
    public function refresh( GoogleConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $endpoint = (string) $this->laravelConfig->get(
            'google.endpoints.token',
            'https://oauth2.googleapis.com/token',
        );

        $response = $this->http->asForm()->post( $endpoint, [
            'client_id'     => (string) $this->config->getClientId(),
            'client_secret' => (string) $this->config->getClientSecret(),
            'refresh_token' => (string) $connection->refresh_token,
            'grant_type'    => 'refresh_token',
        ] );

        if ( ! $response->successful() ) {
            $body  = $response->json();
            $error = is_array( $body ) ? ( $body[ 'error' ] ?? 'refresh_failed' ) : 'refresh_failed';

            if ( 'invalid_grant' === $error ) {
                $connection->markDisconnected( __( 'Refresh token revoked or expired.' ) );
            }

            throw new TokenRefreshException(
                __( 'Google token refresh failed: :error', [ 'error' => $error ] ),
            );
        }

        $payload = $response->json();

        $connection->access_token = $payload[ 'access_token' ] ?? null;
        $connection->token_type   = $payload[ 'token_type' ] ?? 'Bearer';

        if ( isset( $payload[ 'expires_in' ] ) ) {
            $connection->expires_at = Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] );
        }

        if ( ! empty( $payload[ 'refresh_token' ] ) ) {
            $connection->refresh_token = $payload[ 'refresh_token' ];
        }

        if ( ! empty( $payload[ 'scope' ] ) ) {
            $connection->scopes = explode( ' ', (string) $payload[ 'scope' ] );
        }

        $connection->save();

        return (string) $connection->access_token;
    }
}
