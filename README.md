# ArtisanPack UI Google

Shared Google OAuth2 authentication, token storage/refresh, and scope management that powers ArtisanPack UI's Google service integrations. Provider packages like [`artisanpack-ui/analytics-google`](https://github.com/ArtisanPack-UI/analytics-google), [`artisanpack-ui/google-search-console`](https://github.com/ArtisanPack-UI/google-search-console), and [`artisanpack-ui/google-tag-manager`](https://github.com/ArtisanPack-UI/google-tag-manager) sit on top of this package.

- [What this package does](#what-this-package-does)
- [Installation](#installation)
- [Google Cloud Console setup](#google-cloud-console-setup)
- [Credential storage: config vs. database vs. CMS](#credential-storage)
- [Connecting a user](#connecting-a-user)
- [Registering scopes from a service package](#registering-scopes-from-a-service-package)
- [Incremental consent](#incremental-consent)
- [Connection-management UI](#connection-management-ui)
- [Making API calls](#making-api-calls)
- [Configuration reference](#configuration-reference)
- [Contributing](#contributing)

## What this package does

`artisanpack-ui/google` is the shared plumbing that every ArtisanPack UI Google integration sits on top of. It owns:

- The **OAuth2 authorization-code + PKCE flow** — building the consent URL, exchanging the callback code, extracting the user's Google identity from the returned `id_token`.
- **Encrypted token storage** on a per-user `google_connections` model, with transparent refresh via the token manager.
- A **scope registry** that lets any installed service package contribute the scopes it needs. Consent covers the union so users only see one screen.
- **Credential storage drivers** (config file, database, or CMS Settings) so credentials can live wherever a project already stores its secrets.

Service packages (Analytics, Search Console, Tag Manager, …) declare the scopes they need and, once a user has connected, call `Google::tokens()->getValidAccessToken( $connection )` to make authenticated API calls. They never handle OAuth themselves.

## Installation

```bash
composer require artisanpack-ui/google
```

The service provider and `Google` facade are auto-discovered.

Publish and run migrations to create the `google_configurations` and `google_connections` tables:

```bash
php artisan vendor:publish --tag=google-migrations
php artisan migrate
```

Optionally publish the config file to customize routes, drivers, or endpoints:

```bash
php artisan vendor:publish --tag=google-config
```

### Optional peer packages

| Package | What it enables |
|---|---|
| [`livewire/livewire`](https://livewire.laravel.com/) `^3.6` | The `<livewire:google-connection-manager />` connect/disconnect/status UI. |
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | The `cms` credential driver — stores credentials via the CMS Settings module. |

Neither is required; the base package boots and works without them.

## Google Cloud Console setup

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and create (or select) a project.
2. Navigate to **APIs & Services → OAuth consent screen**. Choose **External** unless every user has a Workspace account on the same domain, then fill in the app name, support email, and developer contact.
3. Under **Scopes**, add every scope your installed service packages require. Consent screens for external apps in "Testing" mode are limited to added test users; publish the app when you're ready for production traffic.
4. Enable the APIs your service packages depend on (Analytics Data API, Search Console API, Tag Manager API, …) under **APIs & Services → Library**.
5. Go to **APIs & Services → Credentials → Create Credentials → OAuth client ID**.
    - Application type: **Web application**
    - Authorized redirect URI: `https://your-app.test/google/auth/callback` (adjust prefix if you change `google.routes.prefix`).
6. Copy the generated **Client ID** and **Client secret**.
7. Save them into whichever credential driver you've chosen (see below).

## Credential storage

`artisanpack-ui/google` supports three credential storage drivers. Choose one by setting `GOOGLE_CONFIG_DRIVER` (or `config('google.driver')`).

### `config` (default)

Reads credentials from `config/google.php` / `.env`. Best for single-tenant apps where credentials belong in the deploy pipeline:

```env
GOOGLE_CONFIG_DRIVER=config
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://your-app.test/google/auth/callback
```

The config driver is read-only; `Google::config()->save()` throws.

### `database`

Stores credentials in the `google_configurations` table. The client secret is encrypted with Laravel's `Encrypter` (`APP_KEY`) before it is written. Good for multi-tenant apps or admin-UI-managed credentials:

```env
GOOGLE_CONFIG_DRIVER=database
```

```php
Google::config()->save( [
    'client_id'     => 'your-client-id',
    'client_secret' => 'your-client-secret',
    'redirect_uri'  => 'https://your-app.test/google/auth/callback',
] );
```

If you rotate `APP_KEY` without re-encrypting the stored secret, the driver logs a warning and treats the row as unconfigured.

### `cms` (optional, requires `artisanpack-ui/cms-framework`)

Delegates get/set to the CMS framework's Settings module, so Google credentials live alongside every other site-level setting:

```env
GOOGLE_CONFIG_DRIVER=cms
```

The client secret is encrypted before it is passed to `apUpdateSetting()`. Reading and writing goes through `apGetSetting()` / `apUpdateSetting()`, so any Settings API a project already exposes can manage these credentials. If the CMS framework is not installed, the driver's setting keys are simply never registered — you get a clear "not configured" state instead of a hard boot error.

## Connecting a user

The base package ships two web routes (mounted under `google.routes.prefix`, default `/google/auth`) and two more added for incremental consent and disconnection:

| Route | Method | Name |
|---|---|---|
| `/connect` | GET | `google.auth.connect` |
| `/callback` | GET | `google.auth.callback` |
| `/reauthorize` | GET | `google.auth.reauthorize` |
| `/disconnect` | POST | `google.auth.disconnect` |

Send an authenticated user to `route('google.auth.connect')`:

```blade
<a href="{{ route('google.auth.connect') }}">Connect Google</a>
```

The controller redirects them to Google's consent screen, they return to `/callback`, the code is exchanged, and a `GoogleConnection` row is written for the authenticated user. Redirects afterwards honor `google.routes.redirect_after_connect` and `google.routes.redirect_after_error`.

## Registering scopes from a service package

Service packages contribute scopes via the `ap.google.scopes` filter hook (from [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks)):

```php
use ArtisanPackUI\Hooks\Facades\Filter;

// In your service provider's boot() method:
Filter::add( 'ap.google.scopes', function ( array $scopes ): array {
    $scopes[] = 'https://www.googleapis.com/auth/analytics.readonly';
    return $scopes;
} );
```

At consent time the base package unions every contributed scope with the baseline (`openid`, `userinfo.email`, `userinfo.profile`) and de-duplicates. A single consent screen covers every dependent service — users don't get a per-package prompt.

Applications that need to add a scope without a service provider can call `Google::scopes()->register( $scope )` at runtime instead.

## Incremental consent

When a new service package is installed after the account is already connected, the scope registry starts returning scopes the connection doesn't hold. The connection-management component surfaces a **Reauthorize** action; the `google.auth.reauthorize` route hits `OAuthManager::reauthorizationUrl()` and asks Google for **only the missing scopes** with `include_granted_scopes=true` — Google merges the new grant with the existing one so the user isn't reprompted for scopes they've already approved.

If your app has its own UI, call the manager directly:

```php
$granted = $connection->grantedScopes();
$url     = Google::oauth()->reauthorizationUrl( $userId, $granted );
```

## Connection-management UI

The package ships three interchangeable connection-management components — one Livewire, one React, one Vue — all backed by the same routes and JSON status endpoint. Each shows:

- A **Connect Google** button when the user is disconnected.
- **Connected as {email}**, plus **Disconnect** and **Reauthorize** actions, when connected.
- A prompt to reauthorize when the scope registry grows (see incremental consent above).
- The scopes that will be requested (or that have been granted) in a collapsible details block.

Scope-limited to connect / disconnect / status by design — no per-service enable/disable toggles.

### Livewire

If `livewire/livewire` is installed, mount:

```blade
<livewire:google-connection-manager />
```

### React

The React and Vue components ship as **source** under `resources/js/` — no prebuilt npm package. Either publish the assets into your app's tree:

```bash
php artisan vendor:publish --tag=google-js
```

…or point your bundler at the package directly. Then import:

```tsx
import { GoogleConnectionManager } from '../vendor/google/react';
// or, if you kept the files inside vendor/ and Vite is configured to see them:
// import { GoogleConnectionManager } from '@artisanpack-ui/google/react';

export function IntegrationsPage() {
    return (
        <GoogleConnectionManager
            onDisconnected={ () => console.log( 'disconnected' ) }
        />
    );
}
```

Props (all optional):

| Prop | Type | Default | Purpose |
|---|---|---|---|
| `statusUrl` | `string` | `/google/auth/status` | Override the status endpoint if you've changed `google.routes.prefix`. |
| `csrfToken` | `string \| null` | Read from `<meta name="csrf-token">` | Sent as `X-CSRF-TOKEN` on the disconnect POST. |
| `onDisconnected` | `() => void` |  | Fires after a successful disconnect. |
| `onStatusChanged` | `(status) => void` |  | Fires whenever the status payload refreshes. |
| `className` | `string` |  | Appended to the root element's class. |

### Vue 3

```vue
<script setup lang="ts">
import { GoogleConnectionManager } from '../vendor/google/vue';
</script>

<template>
    <GoogleConnectionManager @disconnected="onDisconnected" />
</template>
```

Props and emits mirror the React component; see the source for the exact shape.

### Building your own

The React and Vue components are a convenience. Any UI framework can consume:

- `GET /google/auth/status` → JSON status payload.
- `GET /google/auth/connect` → redirect to Google.
- `GET /google/auth/reauthorize` → redirect for incremental consent.
- `POST /google/auth/disconnect` → mark disconnected (send the CSRF token).

The same status payload the JS components consume is what the shared `resources/js/shared/api.ts` module type-encodes — use it as a reference for your own client.

## Making API calls

Service packages retrieve a valid access token via the token manager. It refreshes transparently when the current token is within 60 seconds of expiring, and marks the connection disconnected on `invalid_grant`:

```php
use ArtisanPackUI\Google\Facades\Google;

$connection = $user->googleConnection; // or however you load it
$token      = Google::tokens()->getValidAccessToken( $connection );

Http::withToken( $token )
    ->get( 'https://analyticsdata.googleapis.com/v1beta/...' );
```

## Configuration reference

Key options in `config/google.php`:

| Key | Default | Meaning |
|---|---|---|
| `client_id` / `client_secret` / `redirect_uri` | `env(...)` | Credentials used by the `config` driver. |
| `driver` | `config` | Credential driver: `config`, `database`, or `cms`. |
| `endpoints.authorize` | Google auth URL | Overridable for testing. |
| `endpoints.token` | Google token URL | Overridable for testing. |
| `routes.enabled` | `true` | Set false to skip the built-in web routes. |
| `routes.prefix` | `google/auth` | Prefix for the four package routes. |
| `routes.middleware` | `['web']` | Middleware applied to the built-in routes. |
| `routes.redirect_after_connect` | `/` | Path or route name to send users to after connect/disconnect. |
| `routes.redirect_after_error` | `/` | Path or route name for OAuth errors. |
| `user_model` | `App\Models\User` | The user model `GoogleConnection` belongs to. |

## Contributing

Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.
