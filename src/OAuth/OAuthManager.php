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

use ArtisanPackUI\Google\Broker\BrokerClient;
use ArtisanPackUI\Google\Broker\BrokerCredentials;
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Drives the authorization-code flow with PKCE.
 *
 * `authorizationUrl()` builds the URL to send the user to, stashing
 * `state` and `code_verifier` in the session. `handleCallback()`
 * validates state, exchanges the returned code, and persists the
 * connection. The Google calls themselves go through the stateless
 * {@see GoogleClient}, or through {@see BrokerClient} when
 * `google.mode` is `broker`.
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
        return $this->buildAuthorizationUrl( $userId, $override ?? $this->scopes->all() );
    }

    /**
     * Build an incremental-consent URL that only requests newly-registered scopes.
     *
     * Used when a service package is installed after the account is already
     * connected — Google's `include_granted_scopes=true` means the resulting
     * grant is additive, so we only need to send the delta between what the
     * connection already holds and what the scope registry now requires. If
     * nothing is missing the caller should short-circuit; this method still
     * returns a valid URL for the union to keep the API predictable.
     *
     * @since 1.0.0
     *
     * @param  int|string  $userId  The user reauthorizing.
     * @param  array<int, string>  $grantedScopes  Scopes the connection currently holds.
     */
    public function reauthorizationUrl( int|string $userId, array $grantedScopes ): string
    {
        $missing = $this->scopes->missing( $grantedScopes );

        // Nothing missing → send the full union so the URL is still meaningful
        // if the caller decides to force a consent screen anyway.
        $requested = [] === $missing ? $this->scopes->all() : $missing;

        return $this->buildAuthorizationUrl( $userId, $requested );
    }

    /**
     * Handle the OAuth callback: verify state, exchange the code, and persist.
     *
     * In direct mode the code goes to Google with the PKCE verifier from the
     * session; in broker mode the broker's one-time code goes to the broker.
     * Either way the result is stored on the user's {@see GoogleConnection}.
     *
     * @since 1.0.0
     */
    public function handleCallback( string $code, string $returnedState ): GoogleConnection
    {
        $storedState    = $this->session->pull( self::SESSION_STATE );
        $verifier       = $this->session->pull( self::SESSION_VERIFIER );
        $userId         = $this->session->pull( self::SESSION_USER_ID );
        $usesBroker     = $this->usesBroker();

        if ( empty( $storedState ) || ! hash_equals( (string) $storedState, $returnedState ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }

        if ( ! $usesBroker && empty( $verifier ) ) {
            throw new OAuthException( __( 'PKCE code verifier missing from session.' ) );
        }

        if ( empty( $userId ) ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $tokens = $usesBroker
            ? $this->brokerClient()->exchangeCode( $code )
            : $this->client()->exchangeCode( $code, (string) $verifier );

        return $this->persist( $userId, $tokens );
    }

    /**
     * Whether a license `renew_url` from the callback is safe to show the user.
     *
     * Only true in broker mode, for URLs on the broker's own host.
     *
     * @since 1.2.0
     */
    public function isTrustedRenewUrl( ?string $url ): bool
    {
        if ( ! $this->usesBroker() ) {
            return false;
        }

        $credentials = BrokerCredentials::fromConfig( $this->laravelConfig );

        return null !== $credentials
            && ( new BrokerClient( $credentials, $this->http ) )->isTrustedRenewUrl( $url );
    }

    /**
     * Whether the package is in broker client mode.
     *
     * @since 1.2.0
     */
    public function usesBroker(): bool
    {
        return BrokerClient::isEnabled( $this->laravelConfig );
    }

    /**
     * Stateless Google client built from the configured credentials.
     *
     * @since 1.2.0
     */
    public function client(): GoogleClient
    {
        return GoogleClient::make( $this->config, $this->http, $this->laravelConfig );
    }

    /**
     * Broker client built from the configured broker credentials.
     *
     * @since 1.2.0
     *
     * @throws OAuthException When the broker is not configured.
     */
    public function brokerClient(): BrokerClient
    {
        return BrokerClient::fromConfig( $this->laravelConfig, $this->http );
    }

    /**
     * Save an exchanged token set on the user's connection.
     *
     * @since 1.2.0
     */
    protected function persist( int|string $userId, TokenResponse $tokens ): GoogleConnection
    {
        $connection = GoogleConnection::firstOrNew( [ 'user_id' => $userId ] );

        $connection->google_user_id    = $tokens->accountId ?? $connection->google_user_id;
        $connection->email             = $tokens->accountEmail ?? $connection->email;
        $connection->access_token      = $tokens->accessToken;
        $connection->token_type        = $tokens->tokenType;
        $connection->scopes            = [] === $tokens->scopes ? $this->scopes->all() : $tokens->scopes;
        $connection->expires_at        = $tokens->expiresAt;
        $connection->status            = GoogleConnection::STATUS_CONNECTED;
        $connection->disconnect_reason = null;

        // Google only returns a refresh_token on the first consent (and on
        // subsequent consents when prompt=consent is used with a new grant).
        // Incremental-consent regrants typically omit it — we must preserve
        // whatever we already have on file rather than nulling it out and
        // silently disabling refresh for the user.
        if ( null !== $tokens->refreshToken ) {
            $connection->refresh_token = $tokens->refreshToken;
        }

        $connection->save();

        return $connection;
    }

    /**
     * Shared URL builder used by both the initial and incremental consent flows.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $scopes  Scopes to request.
     */
    protected function buildAuthorizationUrl( int|string $userId, array $scopes ): string
    {
        if ( $this->usesBroker() ) {
            return $this->buildBrokerAuthorizationUrl( $userId, $scopes );
        }

        if ( ! $this->config->isConfigured() ) {
            throw new OAuthException( __( 'Google OAuth credentials are not configured.' ) );
        }

        $state    = Str::random( 40 );
        $verifier = GoogleClient::generateCodeVerifier();

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->put( self::SESSION_VERIFIER, $verifier );
        $this->session->put( self::SESSION_USER_ID, $userId );

        return $this->client()->authorizationUrl( $state, $scopes, [], $verifier );
    }

    /**
     * Build the signed broker `/authorize` URL. The broker runs PKCE with
     * Google itself, so only state and the user are kept in the session.
     *
     * @since 1.2.0
     *
     * @param  array<int, string>  $scopes  Scopes to request.
     */
    protected function buildBrokerAuthorizationUrl( int|string $userId, array $scopes ): string
    {
        $broker = $this->brokerClient();
        $state  = Str::random( 40 );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->forget( self::SESSION_VERIFIER );
        $this->session->put( self::SESSION_USER_ID, $userId );

        return $broker->authorizationUrl( $state, $this->brokerReturnUrl(), $scopes );
    }

    /**
     * The URL the broker sends the browser back to: the configured
     * `google.broker.return_url`, else the package's callback route.
     *
     * @since 1.2.0
     */
    protected function brokerReturnUrl(): string
    {
        $configured = (string) $this->laravelConfig->get( 'google.broker.return_url', '' );

        if ( '' !== $configured ) {
            return $configured;
        }

        if ( ! Route::has( 'google.auth.callback' ) ) {
            throw new OAuthException( __( 'Set google.broker.return_url when the package routes are disabled.' ) );
        }

        return route( 'google.auth.callback' );
    }
}
