<?php

/**
 * Connection-management Livewire component.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Livewire;

use ArtisanPackUI\Google\Scopes\ScopeRegistry;
use ArtisanPackUI\Google\Support\ConnectionState;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Connect / disconnect / status surface for the current user's Google account.
 *
 * Both this component and the JSON status endpoint delegate to
 * {@see ConnectionState} for their view model, so the two UIs can never drift
 * on things like "when do we show the reauthorize banner" or "what counts as
 * connected".
 *
 * Deliberately scope-limited to connect / disconnect / status — no per-service
 * enable/disable toggles.
 *
 * @since 1.0.0
 */
class ConnectionManager extends Component
{
    /**
     * Force a re-fetch after connect/disconnect flows redirect back.
     */
    protected $listeners = [ 'google-connection-updated' => '$refresh' ];

    public function render(): View
    {
        $user  = auth()->user();
        $state = ConnectionState::forUser(
            $user?->getAuthIdentifier(),
            app( ScopeRegistry::class ),
        );

        return view( 'google::livewire.connection-manager', [
            'state' => $state,
        ] );
    }
}
