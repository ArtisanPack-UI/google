<?php

/**
 * Main Google class.
 *
 * Entry point for the shared Google OAuth2 authentication, token
 * storage/refresh, and scope management surface that powers the
 * ArtisanPack UI Google service integrations. Accessed via the
 * `google()` helper function or the Google facade.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google;

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use ArtisanPackUI\Google\OAuth\OAuthManager;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use ArtisanPackUI\Google\Tokens\TokenManager;

/**
 * Convenience aggregator for the Google package services.
 *
 * Provides quick access to the configuration repository, scope
 * registry, token manager, and OAuth manager. Also serves as the
 * target of the `Google` facade and `google()` helper.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */
class Google
{
    public function __construct(
        protected ConfigurationRepository $config,
        protected ScopeRegistry $scopes,
        protected TokenManager $tokens,
        protected OAuthManager $oauth,
    ) {
    }

    public function config(): ConfigurationRepository
    {
        return $this->config;
    }

    public function scopes(): ScopeRegistry
    {
        return $this->scopes;
    }

    public function tokens(): TokenManager
    {
        return $this->tokens;
    }

    public function oauth(): OAuthManager
    {
        return $this->oauth;
    }
}
