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
    public function __construct( protected OAuthManager $oauth )
    {
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
