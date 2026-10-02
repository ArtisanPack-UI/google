<?php

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Exceptions;

use ArtisanPackUI\Google\Exceptions\Concerns\CarriesOAuthError;
use RuntimeException;

/**
 * Thrown when the OAuth authorization flow fails.
 *
 * @since 1.0.0
 */
class OAuthException extends RuntimeException
{
    use CarriesOAuthError;
}
