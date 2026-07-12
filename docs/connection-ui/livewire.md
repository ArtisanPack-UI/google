---
title: Livewire Component
---

# Livewire Component

The `<livewire:google-connection-manager />` component is auto-registered when `livewire/livewire ^3.6` is installed. It renders server-side, so it works in any Blade view without touching a bundler.

## Requirements

- `livewire/livewire ^3.6` installed and running.

If Livewire isn't installed, `GoogleServiceProvider::registerLivewireComponents()` returns without registering — the base package boots cleanly and the JSON status endpoint still works for the React and Vue surfaces.

## Basic usage

```blade
<livewire:google-connection-manager />
```

The component reads the current auth user, builds a `ConnectionState`, and renders `google::livewire.connection-manager`.

## Behavior

- **Not authenticated** — the component still renders, but shows the disconnected state. Wrap it in an `@auth` block if you don't want that.
- **Disconnected** — shows a "Connect Google" link that points at `route('google.auth.connect')`.
- **Connected** — shows "Connected as {email}", a POST-form Disconnect button, and (when `needsReauthorize`) a Reauthorize link + count of missing scopes.
- **Details** — a collapsible `<details>` block lists granted or required scopes depending on state.

## Refresh after connect / disconnect

Because the connect flow redirects away to Google and back, the browser navigation resets the whole page — the component re-renders with fresh state on its own.

For same-page updates (e.g., disconnect via AJAX), dispatch the `google-connection-updated` event and the component will re-render:

```blade
<script>
    Livewire.dispatch('google-connection-updated');
</script>
```

The component listens for it via `protected $listeners = [ 'google-connection-updated' => '$refresh' ]`.

## Customizing the view

Publish the view and edit `resources/views/vendor/google/livewire/connection-manager.blade.php`:

```bash
php artisan vendor:publish --tag=google-views
```

Laravel's view resolver prefers the published copy over the package copy, so your edits win.

Alternatively, override the view registration entirely from your service provider:

```php
$this->loadViewsFrom( resource_path( 'views/my-google-views' ), 'google' );
```

## Using ArtisanPack UI components inside

The default view uses vanilla HTML + Tailwind so the package doesn't hard-depend on `livewire-ui-components`. If your app is already on that package, publish and rewrite the view with ArtisanPack UI components:

```blade
@if( $state->isConnected )
    <x-artisanpack-alert type="info">
        {{ __( 'Connected as :email', [ 'email' => $state->email ] ) }}
    </x-artisanpack-alert>

    @if( $state->needsReauthorize )
        <x-artisanpack-alert type="warning">
            <a href="{{ route('google.auth.reauthorize') }}">{{ __( 'Reauthorize' ) }}</a>
        </x-artisanpack-alert>
    @endif

    <form method="POST" action="{{ route('google.auth.disconnect') }}">
        @csrf
        <x-artisanpack-button type="submit" color="error">
            {{ __( 'Disconnect' ) }}
        </x-artisanpack-button>
    </form>
@else
    <x-artisanpack-button :href="route('google.auth.connect')" color="primary">
        {{ __( 'Connect Google' ) }}
    </x-artisanpack-button>
@endif
```

## The class

`ArtisanPackUI\Google\Livewire\ConnectionManager`:

```php
class ConnectionManager extends Component
{
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
```

Deliberately minimal — every rule about what to show lives in [`ConnectionState`](Connection-Model) so the Livewire, React, and Vue surfaces can't drift.

## Testing

```php
use ArtisanPackUI\Google\Livewire\ConnectionManager;
use Livewire\Livewire;

Livewire::actingAs( $user )
    ->test( ConnectionManager::class )
    ->assertSee( 'Connect Google' );
```

For the connected state, seed a `GoogleConnection` for `$user` first:

```php
GoogleConnection::factory()->for( $user )->create( [
    'email'  => 'you@example.com',
    'scopes' => [ 'openid', 'https://www.googleapis.com/auth/userinfo.email' ],
] );

Livewire::actingAs( $user )
    ->test( ConnectionManager::class )
    ->assertSee( 'Connected as' )
    ->assertSee( 'you@example.com' );
```
