<?php

/**
 * Google OAuth controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Http\Controllers;

use ArtisanPackUI\Google\Exceptions\OAuthException;
use ArtisanPackUI\Google\OAuth\OAuthManager;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use ArtisanPackUI\Google\Support\ConnectionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Handles the connect + callback endpoints for the OAuth flow.
 *
 * @since 1.0.0
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        protected OAuthManager $oauth,
        protected ScopeRegistry $scopes,
    ) {
    }

    /**
     * Return the current user's connection state as JSON.
     *
     * This is the data source for the React and Vue connection-management
     * components shipped in `resources/js/`. Livewire consumes the same
     * information through server-side rendering instead.
     *
     * @since 1.0.0
     */
    public function status( Request $request ): JsonResponse
    {
        $user = $request->user();

        if ( null === $user ) {
            return new JsonResponse( [ 'message' => __( 'Unauthenticated.' ) ], 401 );
        }

        $state = ConnectionState::forUser( $user->getAuthIdentifier(), $this->scopes );

        return new JsonResponse( $state->toArray() + [
            'urls' => [
                'connect'     => route( 'google.auth.connect' ),
                'reauthorize' => route( 'google.auth.reauthorize' ),
                'disconnect'  => route( 'google.auth.disconnect' ),
            ],
        ] );
    }

    /**
     * Kick off the authorization redirect.
     *
     * @since 1.0.0
     */
    public function connect( Request $request ): RedirectResponse
    {
        $user = $request->user();

        if ( null === $user ) {
            abort( 401 );
        }

        $url = $this->oauth->authorizationUrl( $user->getAuthIdentifier() );

        return redirect()->away( $url );
    }

    /**
     * Handle the OAuth callback from Google.
     *
     * @since 1.0.0
     */
    public function callback( Request $request ): RedirectResponse
    {
        if ( $error = $request->query( 'error' ) ) {
            return $this->redirectAfterError()->with( 'google.error', (string) $error );
        }

        $code  = (string) $request->query( 'code', '' );
        $state = (string) $request->query( 'state', '' );

        if ( '' === $code || '' === $state ) {
            return $this->redirectAfterError()->with(
                'google.error',
                __( 'Google callback is missing required code or state parameter.' ),
            );
        }

        try {
            $this->oauth->handleCallback( $code, $state );
        } catch ( OAuthException $e ) {
            return $this->redirectAfterError()->with( 'google.error', $e->getMessage() );
        }

        return $this->redirectAfterConnect()->with( 'google.status', 'connected' );
    }

    /**
     * Kick off an incremental-consent redirect for a user already connected.
     *
     * Only the scopes newly registered since the initial connection are
     * requested. If nothing is missing the flow falls back to requesting the
     * full union so the user still lands on a valid consent screen.
     *
     * @since 1.0.0
     */
    public function reauthorize( Request $request ): RedirectResponse
    {
        $user = $request->user();

        if ( null === $user ) {
            abort( 401 );
        }

        $state = ConnectionState::forUser( $user->getAuthIdentifier(), $this->scopes );

        // No existing connection → this route is not the entry point for a
        // fresh consent. Send the caller through /connect so onboarding, gating,
        // and analytics that hang off google.auth.connect still fire.
        if ( ! $state->isConnected ) {
            return redirect()->route( 'google.auth.connect' );
        }

        $url = $this->oauth->reauthorizationUrl(
            $user->getAuthIdentifier(),
            $state->grantedScopes,
        );

        return redirect()->away( $url );
    }

    /**
     * Mark the current user's connection disconnected.
     *
     * Local-only: does not attempt to revoke the token with Google. Callers
     * who need remote revocation can hit the revoke endpoint separately.
     *
     * @since 1.0.0
     */
    public function disconnect( Request $request ): RedirectResponse
    {
        $user = $request->user();

        if ( null === $user ) {
            abort( 401 );
        }

        $state = ConnectionState::forUser( $user->getAuthIdentifier(), $this->scopes );

        $state->connection?->markDisconnected( __( 'Disconnected by user.' ) );

        return $this->redirectAfterConnect()->with( 'google.status', 'disconnected' );
    }

    /**
     * Resolve the "after connect" redirect from config, falling back to '/'.
     *
     * @since 1.0.0
     */
    protected function redirectAfterConnect(): RedirectResponse
    {
        return $this->resolveRedirect( (string) config( 'google.routes.redirect_after_connect', '/' ) );
    }

    /**
     * Resolve the "after error" redirect from config, falling back to '/'.
     *
     * @since 1.0.0
     */
    protected function redirectAfterError(): RedirectResponse
    {
        return $this->resolveRedirect( (string) config( 'google.routes.redirect_after_error', '/' ) );
    }

    /**
     * Interpret a config value as a named route (if it exists) or a path.
     *
     * @since 1.0.0
     */
    protected function resolveRedirect( string $target ): RedirectResponse
    {
        if ( \Illuminate\Support\Facades\Route::has( $target ) ) {
            return redirect()->route( $target );
        }

        return redirect( $target );
    }
}
