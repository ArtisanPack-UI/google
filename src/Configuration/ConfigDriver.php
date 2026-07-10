<?php

/**
 * Config-file driver for app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Configuration;

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Reads Google OAuth app credentials from the Laravel config repository.
 *
 * This driver is read-only; the values are managed via config/env files.
 *
 * @since 1.0.0
 */
class ConfigDriver implements ConfigurationRepository
{
    public function __construct( protected ConfigRepository $config )
    {
    }

    public function getClientId(): ?string
    {
        return $this->config->get( 'google.client_id' );
    }

    public function getClientSecret(): ?string
    {
        return $this->config->get( 'google.client_secret' );
    }

    public function getRedirectUri(): ?string
    {
        return $this->config->get( 'google.redirect_uri' );
    }

    public function save( array $credentials ): void
    {
        throw new RuntimeException(
            'The config driver is read-only. Switch to the database driver to persist credentials.',
        );
    }

    public function isConfigured(): bool
    {
        return ! empty( $this->getClientId() )
            && ! empty( $this->getClientSecret() )
            && ! empty( $this->getRedirectUri() );
    }
}
