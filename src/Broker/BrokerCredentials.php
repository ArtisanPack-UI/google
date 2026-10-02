<?php

/**
 * OAuth broker site credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Broker;

use ArtisanPackUI\Hooks\Facades\Filter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Immutable credentials a site uses to talk to an OAuth broker.
 *
 * A site in broker mode never holds a Google client secret — only the
 * broker's base URL, its own `site_id` and its site secret.
 *
 * @since 1.2.0
 */
final class BrokerCredentials
{
    /**
     * @since 1.2.0
     *
     * @param  string  $url         Broker base URL, without a trailing slash.
     * @param  string  $siteId      This site's ID at the broker.
     * @param  string  $siteSecret  This site's secret (`{id}|{plain}`), sent as the bearer token.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $siteId,
        public readonly string $siteSecret,
    ) {
    }

    /**
     * Resolve broker credentials from `config('google.broker')`.
     *
     * The values pass through the `ap.google.broker.credentials` filter, so
     * a host such as a CMS can supply them from its own settings store.
     * Returns null when any of the three values is missing.
     *
     * @since 1.2.0
     */
    public static function fromConfig( ConfigRepository $config ): ?self
    {
        $values = Filter::apply( 'ap.google.broker.credentials', [
            'url'         => $config->get( 'google.broker.url' ),
            'site_id'     => $config->get( 'google.broker.site_id' ),
            'site_secret' => $config->get( 'google.broker.site_secret' ),
        ] );

        if ( ! is_array( $values ) ) {
            return null;
        }

        $url        = rtrim( trim( (string) ( $values['url'] ?? '' ) ), '/' );
        $siteId     = trim( (string) ( $values['site_id'] ?? '' ) );
        $siteSecret = trim( (string) ( $values['site_secret'] ?? '' ) );

        if ( '' === $url || '' === $siteId || '' === $siteSecret ) {
            return null;
        }

        return new self( $url, $siteId, $siteSecret );
    }

    /**
     * The HMAC key used to sign `/authorize` links.
     *
     * The broker keys signatures with the SHA-256 of the plain part of the
     * site secret (everything after the `|`). A secret without a `|` is
     * treated as all plain part.
     *
     * @since 1.2.0
     */
    public function signingKey(): string
    {
        $separator = strpos( $this->siteSecret, '|' );
        $plain     = false === $separator ? $this->siteSecret : substr( $this->siteSecret, $separator + 1 );

        return hash( 'sha256', $plain );
    }
}
