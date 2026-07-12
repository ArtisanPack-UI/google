---
title: Vue Component
---

# Vue Component

`GoogleConnectionManager` is a Vue 3 SFC that consumes the JSON status endpoint and renders the same connect / disconnect / reauthorize UI as the [Livewire](Connection-UI-Livewire) and [React](Connection-UI-React) surfaces.

## Getting the source

Like the React version, the component ships as **source** — no prebuilt npm package.

**Publish to your app's tree:**

```bash
php artisan vendor:publish --tag=google-js
```

Copies both React and Vue components to `resources/js/vendor/google/`.

**Or import directly from the package**:

```ts
import { GoogleConnectionManager } from '../vendor/artisanpack-ui/google/resources/js/vue';
```

Requires **Vue 3+** and a bundler that can compile `.vue` SFCs (Vite with `@vitejs/plugin-vue`, Webpack + `vue-loader`, etc.).

## Basic usage

```vue
<script setup lang="ts">
import { GoogleConnectionManager } from '../vendor/google/vue';

function onDisconnected() {
    console.log( 'disconnected' );
}
</script>

<template>
    <GoogleConnectionManager @disconnected="onDisconnected" />
</template>
```

The component fetches `/google/auth/status` on mount, renders the appropriate UI, and re-fetches after a successful disconnect.

## Props

Mirrors the React component, all optional:

| Prop | Type | Default | Purpose |
|---|---|---|---|
| `statusUrl` | `string` | `/google/auth/status` | Override the status endpoint. |
| `csrfToken` | `string \| null` | Read from `<meta name="csrf-token">` | Sent as `X-CSRF-TOKEN` on the disconnect POST. |
| `labels` | `Partial<GoogleConnectionLabels>` | English defaults | Localized labels. |
| `className` | `string` | — | Appended to the root element's class. |

## Emits

| Event | Payload | Fires when |
|---|---|---|
| `disconnected` | — | After a successful disconnect. |
| `status-changed` | `GoogleConnectionStatus` | Whenever the status payload refreshes. |

Listen for them with the Vue `@event` syntax:

```vue
<GoogleConnectionManager
    @disconnected="onDisconnected"
    @status-changed="onStatusChanged"
/>
```

## Labels

Same shape as the React component. Wire from Blade to Vue:

```blade
<div id="google-connection-manager" data-labels="@json([
    'connectButton'    => __( 'Connect Google' ),
    'disconnectButton' => __( 'Disconnect' ),
    // ...
])"></div>
```

```ts
import { createApp } from 'vue';
import { GoogleConnectionManager } from '../vendor/google/vue';

const el     = document.getElementById( 'google-connection-manager' );
const labels = JSON.parse( el?.dataset.labels ?? '{}' );

createApp( {
    components: { GoogleConnectionManager },
    template: '<GoogleConnectionManager :labels="labels" />',
    setup: () => ( { labels } ),
} ).mount( el );
```

See the [React labels section](Connection-UI-React#labels) for the full label shape — it's identical.

## Using inside a Vue SPA

Import and use like any other component:

```vue
<script setup lang="ts">
import { GoogleConnectionManager } from '@/components/GoogleConnectionManager';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const labels = {
    connectButton:     t( 'google.connect' ),
    disconnectButton:  t( 'google.disconnect' ),
    reauthorizeButton: t( 'google.reauthorize' ),
    // ...
    needsReauthorize:  ( count: number ) => t( 'google.needsReauthorize', count ),
};
</script>

<template>
    <div class="settings-page">
        <h1>Integrations</h1>
        <GoogleConnectionManager :labels="labels" />
    </div>
</template>
```

## Using with Inertia + Vue

Wire it up in the page component:

```vue
<script setup lang="ts">
import { GoogleConnectionManager } from '../vendor/google/vue';
import { router } from '@inertiajs/vue3';

function onDisconnected() {
    router.reload( { only: [ 'flash' ] } );
}
</script>

<template>
    <GoogleConnectionManager @disconnected="onDisconnected" />
</template>
```

## The shared API client

Same client as the React component — see [React → The shared API client](Connection-UI-React#the-shared-api-client) for details.

## Testing

Mount with `@vue/test-utils` and stub the fetch:

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { GoogleConnectionManager } from './ConnectionManager.vue';

beforeEach( () => {
    globalThis.fetch = vi.fn().mockResolvedValue( {
        ok:   true,
        json: async () => ( {
            connected: false,
            email:     null,
            urls:      { connect: '/c', reauthorize: '/r', disconnect: '/d' },
            // ...
        } ),
    } );
} );

test( 'renders the connect link when disconnected', async () => {
    const wrapper = mount( GoogleConnectionManager );
    await flushPromises();

    expect( wrapper.text() ).toContain( 'Connect Google' );
} );
```
