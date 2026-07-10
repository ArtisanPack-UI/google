<?php

/**
 * Google package helper functions.
 *
 * Global helper functions for the Google package. Add package-wide
 * helpers here (OAuth flow shortcuts, scope constants, etc.).
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

use ArtisanPackUI\Google\Google;

if ( ! function_exists( 'google' ) ) {
    /**
     * Get the Google instance.
     *
     * @since 1.0.0
     *
     * @return Google
     */
    function google(): Google
    {
        return app( 'google' );
    }
}

// Add your custom helper functions below
