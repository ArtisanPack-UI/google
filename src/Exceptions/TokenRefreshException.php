<?php

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Exceptions;

use RuntimeException;

/**
 * Thrown when a Google OAuth token cannot be refreshed.
 *
 * @since 1.0.0
 */
class TokenRefreshException extends RuntimeException
{
}
