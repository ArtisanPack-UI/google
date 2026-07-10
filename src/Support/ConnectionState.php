<?php

/**
 * Connection-state view model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Support;

use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\Scopes\ScopeRegistry;

/**
 * Shared state model consumed by every connection-management surface.
 *
 * The Livewire component, the JSON status endpoint, and any future
 * server-rendered surface all resolve their view data through this class,
 * so rules like "hide the reauthorize banner when disconnect_reason is set"
 * or "clear the email when not connected" only ever have to change here.
 *
 * @since 1.0.0
 */
class ConnectionState
{
    /**
     * @param  list<string>  $grantedScopes
     * @param  list<string>  $requiredScopes
     * @param  list<string>  $missingScopes
     */
    public function __construct(
        public readonly ?GoogleConnection $connection,
        public readonly bool $isConnected,
        public readonly ?string $email,
        public readonly array $grantedScopes,
        public readonly array $requiredScopes,
        public readonly array $missingScopes,
        public readonly bool $needsReauthorize,
    ) {
    }

    /**
     * Build the state for a given user id.
     *
     * Passing an id instead of a user model keeps this callable from
     * anywhere the auth identifier is known — controllers, Livewire
     * components, jobs — without depending on Illuminate\Contracts\Auth\Authenticatable.
     *
     * @since 1.0.0
     */
    public static function forUser( int|string|null $userId, ScopeRegistry $scopes ): self
    {
        $connection = null;

        if ( null !== $userId ) {
            $connection = GoogleConnection::query()
                ->where( 'user_id', $userId )
                ->first();
        }

        return self::forConnection( $connection, $scopes );
    }

    /**
     * Build the state for an already-loaded connection.
     *
     * @since 1.0.0
     */
    public static function forConnection( ?GoogleConnection $connection, ScopeRegistry $scopes ): self
    {
        $isConnected = null !== $connection && $connection->isConnected();
        $granted     = $isConnected ? $connection->grantedScopes() : [];
        $required    = $scopes->all();
        $missing     = $scopes->missing( $granted );

        return new self(
            connection: $connection,
            isConnected: $isConnected,
            email: $isConnected ? $connection->email : null,
            grantedScopes: $granted,
            requiredScopes: $required,
            missingScopes: $missing,
            needsReauthorize: $isConnected && [] !== $missing,
        );
    }

    /**
     * Render as an array for JSON serialization / Blade prop bags.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connected'        => $this->isConnected,
            'email'            => $this->email,
            'grantedScopes'    => $this->grantedScopes,
            'requiredScopes'   => $this->requiredScopes,
            'missingScopes'    => $this->missingScopes,
            'needsReauthorize' => $this->needsReauthorize,
            'disconnectReason' => $this->connection?->disconnect_reason,
        ];
    }
}
