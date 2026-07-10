<?php

/**
 * OAuth2 authorization-code flow manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\OAuth;

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Drives the authorization-code flow with PKCE.
 *
 * `authorizationUrl()` builds the URL to send the user to, stashing
 * `state` and `code_verifier` in the session. `handleCallback()`
 * validates state, exchanges the returned code, and persists the
 * connection.
 *
 * @since 1.0.0
 */
class OAuthManager
{
    protected const SESSION_STATE    = 'google.oauth.state';

    protected const SESSION_VERIFIER = 'google.oauth.verifier';

    protected const SESSION_USER_ID  = 'google.oauth.user_id';

    public function __construct(
        protected ConfigurationRepository $config,
        protected ConfigRepository $laravelConfig,
        protected Session $session,
        protected HttpFactory $http,
        protected ScopeRegistry $scopes,
    ) {
    }

    /**
     * Build the Google authorization URL for a given user.
     *
     * @since 1.0.0
     *
     * @param  int|string  $userId   The user we're connecting a Google account to.
     * @param  array<int, string>|null  $override Explicit scopes; defaults to registry.
     */
    public function authorizationUrl( int|string $userId, ?array $override = null ): string
    {
        if ( ! $this->config->isConfigured() ) {
            throw new OAuthException( __( 'Google OAuth credentials are not configured.' ) );
        }

        $state     = Str::random( 40 );
        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge( $verifier );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->put( self::SESSION_VERIFIER, $verifier );
        $this->session->put( self::SESSION_USER_ID, $userId );

        $scopes = $override ?? $this->scopes->all();

        $params = [
            'client_id'              => $this->config->getClientId(),
            'redirect_uri'           => $this->config->getRedirectUri(),
            'response_type'          => 'code',
            'scope'                  => implode( ' ', $scopes ),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
            'code_challenge'         => $challenge,
            'code_challenge_method'  => 'S256',
        ];

        $endpoint = (string) $this->laravelConfig->get(
            'google.endpoints.authorize',
            'https://accounts.google.com/o/oauth2/v2/auth',
        );

        return $endpoint . '?' . http_build_query( $params );
    }

    /**
     * Handle the OAuth callback: verify state and exchange the code.
     *
     * @since 1.0.0
     */
    public function handleCallback( string $code, string $returnedState ): GoogleConnection
    {
        $storedState    = $this->session->pull( self::SESSION_STATE );
        $verifier       = $this->session->pull( self::SESSION_VERIFIER );
        $userId         = $this->session->pull( self::SESSION_USER_ID );

        if ( empty( $storedState ) || ! hash_equals( (string) $storedState, $returnedState ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }

        if ( empty( $verifier ) ) {
            throw new OAuthException( __( 'PKCE code verifier missing from session.' ) );
        }

        if ( empty( $userId ) ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $endpoint = (string) $this->laravelConfig->get(
            'google.endpoints.token',
            'https://oauth2.googleapis.com/token',
        );

        $response = $this->http->asForm()->post( $endpoint, [
            'code'          => $code,
            'client_id'     => $this->config->getClientId(),
            'client_secret' => $this->config->getClientSecret(),
            'redirect_uri'  => $this->config->getRedirectUri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => (string) $verifier,
        ] );

        if ( ! $response->successful() ) {
            $body  = $response->json();
            $error = is_array( $body ) ? ( $body[ 'error' ] ?? 'exchange_failed' ) : 'exchange_failed';

            throw new OAuthException(
                __( 'Google code exchange failed: :error', [ 'error' => $error ] ),
            );
        }

        $payload = $response->json();

        $expiresAt = isset( $payload[ 'expires_in' ] )
            ? Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] )
            : null;

        $scopes = isset( $payload[ 'scope' ] )
            ? explode( ' ', (string) $payload[ 'scope' ] )
            : $this->scopes->all();

        [ $googleUserId, $email ] = $this->extractIdentity( $payload[ 'id_token' ] ?? null );

        return GoogleConnection::updateOrCreate(
            [ 'user_id' => $userId ],
            [
                'google_user_id'    => $googleUserId,
                'email'             => $email,
                'access_token'      => $payload[ 'access_token' ] ?? null,
                'refresh_token'     => $payload[ 'refresh_token' ] ?? null,
                'token_type'        => $payload[ 'token_type' ] ?? 'Bearer',
                'scopes'            => $scopes,
                'expires_at'        => $expiresAt,
                'status'            => GoogleConnection::STATUS_CONNECTED,
                'disconnect_reason' => null,
            ],
        );
    }

    /**
     * Decode the `sub` and `email` claims from Google's id_token JWT.
     *
     * Google returns an id_token whenever the `openid` scope is requested
     * (our baseline). We only trust the claims for identity persistence,
     * not authorization, so verification of the JWT signature is not
     * required here — the token came from the TLS-terminated exchange
     * with Google. Returns [ null, null ] if the token is missing or
     * malformed.
     *
     * @since 1.0.0
     *
     * @return array{0: ?string, 1: ?string} [google_user_id, email]
     */
    protected function extractIdentity( ?string $idToken ): array
    {
        if ( empty( $idToken ) ) {
            return [ null, null ];
        }

        $parts = explode( '.', $idToken );
        if ( 3 !== count( $parts ) ) {
            return [ null, null ];
        }

        $payload = base64_decode( strtr( $parts[ 1 ], '-_', '+/' ), true );
        if ( false === $payload ) {
            return [ null, null ];
        }

        $claims = json_decode( $payload, true );
        if ( ! is_array( $claims ) ) {
            return [ null, null ];
        }

        return [
            isset( $claims[ 'sub' ] )   ? (string) $claims[ 'sub' ]   : null,
            isset( $claims[ 'email' ] ) ? (string) $claims[ 'email' ] : null,
        ];
    }

    protected function generateVerifier(): string
    {
        return rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
    }

    protected function generateChallenge( string $verifier ): string
    {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }
}
