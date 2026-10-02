<?php

/**
 * Google OAuth app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\OAuth;

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;

/**
 * Immutable set of Google OAuth app credentials.
 *
 * Lets callers build a {@see GoogleClient} from credentials supplied at
 * runtime (for example an OAuth broker reading them from its own admin
 * settings) instead of only the bound {@see ConfigurationRepository}.
 *
 * @since 1.2.0
 */
final class GoogleCredentials
{
    /**
     * @since 1.2.0
     *
     * @param  string       $clientId      OAuth client ID.
     * @param  string       $clientSecret  OAuth client secret.
     * @param  string|null  $redirectUri   Redirect URI registered with Google. Only the consent URL and code exchange need it.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $redirectUri = null,
    ) {
    }

    /**
     * Build credentials from a configuration repository driver.
     *
     * @since 1.2.0
     */
    public static function fromRepository( ConfigurationRepository $repository ): self
    {
        $redirectUri = $repository->getRedirectUri();

        return new self(
            (string) $repository->getClientId(),
            (string) $repository->getClientSecret(),
            '' === (string) $redirectUri ? null : (string) $redirectUri,
        );
    }
}
