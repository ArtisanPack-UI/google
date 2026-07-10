---
title: React Component
---

# React Component

`GoogleConnectionManager` is a React component that consumes the JSON status endpoint and renders the same connect / disconnect / reauthorize UI as the [[Connection UI/Livewire|Livewire]] and [[Connection UI/Vue|Vue]] surfaces.

## Getting the source

The component ships as **source** under `resources/js/react/` — no prebuilt npm package. Two ways to use it:

**Publish to your app's tree:**

```bash
php artisan vendor:publish --tag=google-js
```

This copies both React and Vue components to `resources/js/vendor/google/`.

**Or point your bundler at the package directly.** In `vite.config.js`:

```js
export default defineConfig({
    resolve: {
        alias: {
            '@artisanpack-ui/google/react': path.resolve(
                __dirname,
                'vendor/artisanpack-ui/google/resources/js/react'
            ),
        },
    },
});
```

Then import:

```tsx
import { GoogleConnectionManager } from '@artisanpack-ui/google/react';
```

Requires **React 18+** and a bundler that can compile `.tsx` (Vite, Webpack + ts-loader, etc.).

## Basic usage

```tsx
import { GoogleConnectionManager } from '../vendor/google/react';

export function IntegrationsPage() {
    return (
        <GoogleConnectionManager
            onDisconnected={ () => console.log( 'disconnected' ) }
        />
    );
}
```

The component fetches `/google/auth/status` on mount, renders the appropriate UI, and re-fetches after a successful disconnect. Errors from the status fetch are caught and rendered with a `Retry` button.

## Props

All optional:

| Prop | Type | Default | Purpose |
|---|---|---|---|
| `statusUrl` | `string` | `/google/auth/status` | Override the status endpoint if you've changed `google.routes.prefix`. |
| `csrfToken` | `string \| null` | Read from `<meta name="csrf-token">` | Sent as `X-CSRF-TOKEN` on the disconnect POST. |
| `labels` | `Partial<GoogleConnectionLabels>` | English defaults from `shared/labels.ts` | Localized labels. See below. |
| `onDisconnected` | `() => void` | — | Fires after a successful disconnect. |
| `onStatusChanged` | `(status: GoogleConnectionStatus) => void` | — | Fires whenever the status payload refreshes. |
| `className` | `string` | — | Appended to the root element's `className`. |

## Labels

The React component can't call Laravel's `__()`, so localization goes through the `labels` prop. Wire it from Blade:

```blade
<div id="google-connection-manager" data-labels="@json([
    'loading'              => __( 'Loading Google connection…' ),
    'connectButton'        => __( 'Connect Google' ),
    'disconnectButton'     => __( 'Disconnect' ),
    'reauthorizeButton'    => __( 'Reauthorize' ),
    'connectedAs'          => __( 'Connected as' ),
    'noAccountConnected'   => __( 'No Google account connected.' ),
    'errorFallback'        => __( 'Unable to load Google connection status.' ),
    'retry'                => __( 'Retry' ),
    'disconnectingButton'  => __( 'Disconnecting…' ),
])"></div>
```

Then read the JSON attribute when mounting:

```tsx
const el     = document.getElementById( 'google-connection-manager' );
const labels = JSON.parse( el?.dataset.labels ?? '{}' );

createRoot( el ).render(
    <GoogleConnectionManager
        labels={{
            ...labels,
            // Pluralization callbacks can't cross the JSON boundary — wire in code:
            needsReauthorize:    ( count ) => trans_choice( 'reauth.count', count ),
            grantedScopesTitle:  ( count ) => `${labels.grantedScopesTitle}: ${count}`,
            requiredScopesTitle: ( count ) => `${labels.requiredScopesTitle}: ${count}`,
        }}
    />
);
```

The full label shape:

```ts
export type GoogleConnectionLabels = {
    loading: string;
    retry: string;
    errorFallback: string;

    connectedAs: string;
    googleAccountFallback: string;
    noAccountConnected: string;

    connectButton: string;
    reauthorizeButton: string;
    disconnectButton: string;
    disconnectingButton: string;

    needsReauthorize:    ( count: number ) => string;
    grantedScopesTitle:  ( count: number ) => string;
    requiredScopesTitle: ( count: number ) => string;
};
```

Every key is optional in the prop; omitted keys fall back to the English defaults exported as `defaultGoogleConnectionLabels`.

## The shared API client

Both the React and Vue components import from `resources/js/shared/api.ts`. If you want to build your own UI on top of the same client:

```ts
import {
    fetchGoogleConnectionStatus,
    disconnectGoogleConnection,
    GoogleConnectionApiError,
    type GoogleConnectionStatus,
} from '../vendor/google/shared/api';

const status = await fetchGoogleConnectionStatus();

if ( ! status.connected ) {
    window.location.href = status.urls.connect;
}

await disconnectGoogleConnection( {
    disconnectUrl: status.urls.disconnect,
    csrfToken:     '...',   // read from <meta name="csrf-token"> when omitted
} );
```

Both helpers throw `GoogleConnectionApiError` on non-OK responses, with the HTTP status attached.

## Ref-stashed callbacks

The component stashes `onStatusChanged` and `onDisconnected` in refs to avoid re-fetching on every parent render. A parent that hoists status into its own state via `onStatusChanged` would otherwise trigger a fresh fetch on every render — and if that fetch updated the parent, an infinite loop.

This is why `onStatusChanged` and `onDisconnected` aren't part of the effect's dependency array — the ref pattern keeps the latest callback callable without listing it as a dep.

## Testing

Mock the fetch calls:

```tsx
import { render, screen } from '@testing-library/react';
import { GoogleConnectionManager } from './ConnectionManager';

beforeEach( () => {
    globalThis.fetch = jest.fn().mockResolvedValue( {
        ok:    true,
        json:  async () => ( {
            connected:        false,
            email:            null,
            grantedScopes:    [],
            requiredScopes:   [ 'openid' ],
            missingScopes:    [ 'openid' ],
            needsReauthorize: false,
            disconnectReason: null,
            urls: { connect: '/c', reauthorize: '/r', disconnect: '/d' },
        } ),
    } );
} );

test( 'renders the connect button when disconnected', async () => {
    render( <GoogleConnectionManager /> );

    expect( await screen.findByRole( 'link', { name: /connect google/i } ) ).toBeInTheDocument();
} );
```
