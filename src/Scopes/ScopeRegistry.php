<?php

/**
 * Scope registry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Scopes;

use ArtisanPackUI\Hooks\Facades\Filter;

/**
 * Collects the union of OAuth scopes required by dependent packages.
 *
 * Packages register scopes by hooking `ap.google.scopes`. At consent
 * time this registry returns the full union so a single consent
 * screen covers every dependent service. The registry also computes
 * the delta vs. currently-granted scopes to support incremental
 * consent.
 *
 * @since 1.0.0
 */
class ScopeRegistry
{
    /**
     * Extra scopes registered imperatively at runtime, in addition to
     * whatever the filter hook contributes.
     *
     * @var list<string>
     */
    protected array $imperative = [];

    /**
     * Openid + email + profile are always required to identify the
     * connecting user. Everything else is contributed by service
     * packages via the filter hook.
     *
     * @var list<string>
     */
    protected array $baseline = [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ];

    /**
     * Imperatively register a scope from application code.
     *
     * Prefer the `ap.google.scopes` filter hook for package-supplied
     * scopes. This method is a convenience for apps that need to add
     * a scope without wiring a service provider.
     *
     * @since 1.0.0
     */
    public function register( string $scope ): void
    {
        $scope = trim( $scope );

        if ( '' === $scope ) {
            return;
        }

        if ( ! in_array( $scope, $this->imperative, true ) ) {
            $this->imperative[] = $scope;
        }
    }

    /**
     * Get the full de-duplicated union of registered scopes.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    public function all(): array
    {
        $filtered = Filter::apply( 'ap.google.scopes', [] );

        if ( ! is_array( $filtered ) ) {
            $filtered = [];
        }

        $merged = array_merge( $this->baseline, $this->imperative, array_values( $filtered ) );
        $merged = array_map( 'strval', $merged );
        $merged = array_map( 'trim', $merged );
        $merged = array_filter( $merged, static fn ( string $s ): bool => '' !== $s );

        return array_values( array_unique( $merged ) );
    }

    /**
     * Compute the set of required scopes not yet granted by the user.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $grantedScopes  Scopes currently granted.
     *
     * @return list<string> Scopes that still need consent.
     */
    public function missing( array $grantedScopes ): array
    {
        $granted = array_map( 'strval', $grantedScopes );

        return array_values( array_diff( $this->all(), $granted ) );
    }

    /**
     * Whether every required scope has already been granted.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $grantedScopes
     */
    public function hasAllRequired( array $grantedScopes ): bool
    {
        return [] === $this->missing( $grantedScopes );
    }
}
