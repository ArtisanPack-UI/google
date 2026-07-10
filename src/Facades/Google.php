<?php

/**
 * Google Facade.
 *
 * Provides static access to the Google class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Google Facade.
 *
 * @see \ArtisanPackUI\Google\Google
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */
class Google extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'google';
    }
}
