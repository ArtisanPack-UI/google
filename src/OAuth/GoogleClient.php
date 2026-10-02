<?php

/**
 * Stateless Google OAuth client.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\OAuth;

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Stateless OAuth primitives for talking to Google directly.
 *
 * Nothing here touches the session or the database: the caller supplies
 * the credentials, `state`, scopes and (optionally) the PKCE verifier, and
 * gets a {@see TokenResponse} back. This is what an OAuth broker relays
 * through, and what {@see OAuthManager} and the token manager wrap to add
 * session handling and persistence.
 *
 * @since 1.2.0
 */
class GoogleClient
{
    public const DEFAULT_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const DEFAULT_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';

    /**
     * @since 1.2.0
     *
     * @param  GoogleCredentials  $credentials        App credentials to authenticate with.
     * @param  HttpFactory        $http               HTTP client factory.
     * @param  string             $authorizeEndpoint  Google consent-screen endpoint.
     * @param  string             $tokenEndpoint      Google token endpoint.
     */
    public function __construct(
        protected GoogleCredentials $credentials,
        protected HttpFactory $http,
        protected string $authorizeEndpoint = self::DEFAULT_AUTHORIZE_ENDPOINT,
        protected string $tokenEndpoint = self::DEFAULT_TOKEN_ENDPOINT,
    ) {
    }

    /**
     * Build a client with the given (or configured) credentials and the
     * endpoints from `config/google.php`.
     *
     * @since 1.2.0
     *
     * @param  ConfigurationRepository|GoogleCredentials  $credentials  Explicit credentials, or a driver to read them from.
     */
    public static function make(
        GoogleCredentials|ConfigurationRepository $credentials,
        HttpFactory $http,
        ConfigRepository $config,
    ): self {
        if ( $credentials instanceof ConfigurationRepository ) {
            $credentials = GoogleCredentials::fromRepository( $credentials );
        }

        return new self(
            $credentials,
            $http,
            (string) $config->get( 'google.endpoints.authorize', self::DEFAULT_AUTHORIZE_ENDPOINT ),
            (string) $config->get( 'google.endpoints.token', self::DEFAULT_TOKEN_ENDPOINT ),
        );
    }

    /**
     * Generate a random PKCE code verifier.
     *
     * @since 1.2.0
     */
    public static function generateCodeVerifier(): string
    {
        return rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
    }

    /**
     * Derive the S256 PKCE code challenge for a verifier.
     *
     * @since 1.2.0
     */
    public static function codeChallenge( string $verifier ): string
    {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }

    /**
     * The credentials this client authenticates with.
     *
     * @since 1.2.0
     */
    public function credentials(): GoogleCredentials
    {
        return $this->credentials;
    }

    /**
     * Build the Google consent URL.
     *
     * Defaults to `access_type=offline`, `prompt=consent` and
     * `include_granted_scopes=true`; anything in `$parameters` overrides
     * them or adds to them (for example `login_hint`). PKCE is only added
     * when a code verifier is supplied.
     *
     * @since 1.2.0
     *
     * @param  string                 $state         Caller-generated state, echoed back on the callback.
     * @param  array<int, string>     $scopes        Scopes to request.
     * @param  array<string, string>  $parameters    Extra or overriding query parameters.
     * @param  string|null            $codeVerifier  PKCE verifier; omit to skip PKCE.
     *
     * @throws OAuthException When the client ID or redirect URI is missing.
     */
    public function authorizationUrl(
        string $state,
        array $scopes,
        array $parameters = [],
        ?string $codeVerifier = null,
    ): string {
        if ( '' === $this->credentials->clientId || null === $this->credentials->redirectUri ) {
            throw new OAuthException( __( 'Google OAuth credentials are not configured.' ) );
        }

        $params = [
            'client_id'              => $this->credentials->clientId,
            'redirect_uri'           => $this->credentials->redirectUri,
            'response_type'          => 'code',
            'scope'                  => implode( ' ', $scopes ),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ];

        if ( null !== $codeVerifier ) {
            $params['code_challenge']        = self::codeChallenge( $codeVerifier );
            $params['code_challenge_method'] = 'S256';
        }

        return $this->authorizeEndpoint . '?' . http_build_query( array_merge( $params, $parameters ) );
    }

    /**
     * Exchange an authorization code for tokens without persisting them.
     *
     * @since 1.2.0
     *
     * @param  string       $code          The `code` Google returned to the redirect URI.
     * @param  string|null  $codeVerifier  The PKCE verifier used to build the consent URL, if any.
     *
     * @throws OAuthException When Google rejects the exchange.
     */
    public function exchangeCode( string $code, ?string $codeVerifier = null ): TokenResponse
    {
        $form = [
            'code'          => $code,
            'client_id'     => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'redirect_uri'  => (string) $this->credentials->redirectUri,
            'grant_type'    => 'authorization_code',
        ];

        if ( null !== $codeVerifier ) {
            $form['code_verifier'] = $codeVerifier;
        }

        $response = $this->http->asForm()->post( $this->tokenEndpoint, $form );
        $error    = $this->errorFrom( $response, 'exchange_failed' );

        if ( null !== $error ) {
            throw new OAuthException(
                __( 'Google code exchange failed: :error', [ 'error' => $error ] ),
                $error,
            );
        }

        return TokenResponse::fromGoogle( $response->json() );
    }

    /**
     * Refresh an access token from a raw refresh-token string.
     *
     * Google usually does not rotate refresh tokens; when it doesn't, the
     * returned response carries the one passed in.
     *
     * @since 1.2.0
     *
     * @throws TokenRefreshException When Google rejects the refresh. `getError()` is `invalid_grant` for a revoked grant.
     */
    public function refresh( string $refreshToken ): TokenResponse
    {
        $response = $this->http->asForm()->post( $this->tokenEndpoint, [
            'client_id'     => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ] );

        $error = $this->errorFrom( $response, 'refresh_failed' );

        if ( null !== $error ) {
            throw new TokenRefreshException(
                __( 'Google token refresh failed: :error', [ 'error' => $error ] ),
                $error,
            );
        }

        return TokenResponse::fromGoogle( $response->json(), $refreshToken );
    }

    /**
     * Resolve the OAuth error code for a failed (or token-less) response.
     *
     * @since 1.2.0
     *
     * @return string|null Null when the response is a usable token payload.
     */
    protected function errorFrom( Response $response, string $fallback ): ?string
    {
        $body = $response->json();

        if ( ! $response->successful() ) {
            return is_array( $body ) && is_string( $body['error'] ?? null ) ? $body['error'] : $fallback;
        }

        return is_array( $body ) && is_string( $body['access_token'] ?? null ) && '' !== $body['access_token'] ? null : $fallback;
    }
}
