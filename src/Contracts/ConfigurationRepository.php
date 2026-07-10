<?php

/**
 * Configuration repository contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Contracts;

/**
 * Contract for app credential storage drivers.
 *
 * Implementations back either config/env files or the database.
 * OAuth tokens are NOT stored here — see the connection model.
 *
 * @since 1.0.0
 */
interface ConfigurationRepository
{
    /**
     * Get the OAuth client ID.
     *
     * @since 1.0.0
     */
    public function getClientId(): ?string;

    /**
     * Get the OAuth client secret.
     *
     * @since 1.0.0
     */
    public function getClientSecret(): ?string;

    /**
     * Get the redirect URI registered with Google.
     *
     * @since 1.0.0
     */
    public function getRedirectUri(): ?string;

    /**
     * Persist a full credential set.
     *
     * Drivers that are read-only (like the config driver) may throw
     * a RuntimeException.
     *
     * @since 1.0.0
     *
     * @param  array<string, string|null>  $credentials  Keys: client_id, client_secret, redirect_uri.
     */
    public function save( array $credentials ): void;

    /**
     * Whether the repository has a full, usable credential set.
     *
     * @since 1.0.0
     */
    public function isConfigured(): bool;
}
