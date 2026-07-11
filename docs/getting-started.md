---
title: Getting Started
---

# Getting Started

Welcome to ArtisanPack UI Google. This guide walks through the shortest path from `composer require` to a user with a working Google connection.

See also: [[Installation]], [[Credential Drivers]], [[OAuth Flow]], and [[Connection UI]].

## Requirements

- PHP 8.2+
- Laravel 10.x, 11.x, 12.x, or 13.x
- A Google Cloud project with an OAuth 2.0 client ID (Web application type)

Optional peer packages:

| Package | What it enables |
|---|---|
| [`livewire/livewire`](https://livewire.laravel.com/) `^3.6` | The `<livewire:google-connection-manager />` connect/disconnect/status UI. |
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | The [[Credential Drivers#cms|cms credential driver]] — stores credentials via the CMS Settings module. |

Neither is required; the base package boots and works without them.

## 1. Install

```bash
composer require artisanpack-ui/google
```

The service provider and `Google` facade are auto-discovered.

## 2. Run migrations

```bash
php artisan vendor:publish --tag=google-migrations
php artisan migrate
```

This creates the `google_configurations` and `google_connections` tables. See [[Connection Model]] for the schema.

## 3. Register your Google OAuth client

Follow the [[Installation#Google Cloud Console setup|Google Cloud Console]] walkthrough to create an OAuth 2.0 client ID and register a redirect URI. Copy the client ID and secret.

## 4. Store your credentials

The default driver reads from `config/google.php` / `.env`:

```env
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://your-app.test/google/auth/callback
```

For multi-tenant apps or credentials managed through an admin UI, switch to the `database` or `cms` driver — see [[Credential Drivers]].

## 5. Connect a user

Send an authenticated user to the `google.auth.connect` route:

```blade
<a href="{{ route('google.auth.connect') }}">Connect Google</a>
```

They'll be redirected to Google's consent screen, then bounced back to `/google/auth/callback`. On success, a `GoogleConnection` row is written for the authenticated user and they're redirected to `google.routes.redirect_after_connect` (default `/`).

Full walkthrough: [[OAuth Flow]].

## 6. Make an authenticated API call

Service packages retrieve a valid access token via the token manager. It refreshes transparently when the current token is within 60 seconds of expiring:

```php
use ArtisanPackUI\Google\Facades\Google;
use Illuminate\Support\Facades\Http;

$connection = $user->googleConnection;
$token      = Google::tokens()->getValidAccessToken( $connection );

Http::withToken( $token )
    ->get( 'https://analyticsdata.googleapis.com/v1beta/...' );
```

More on refresh behavior: [[Tokens]].

## 7. Add a connection-management UI

If Livewire is installed, drop the component in a Blade view:

```blade
<livewire:google-connection-manager />
```

React and Vue equivalents ship as source under `resources/js/`. See [[Connection UI]] for props, events, and how to build your own.

## Next steps

- [[Installation]] — full install walkthrough, Google Cloud setup, publishing assets.
- [[Credential Drivers]] — config vs. database vs. CMS.
- [[OAuth Flow]] — connect, callback, reauthorize, and disconnect internals.
- [[Scopes]] — how service packages register scopes and how incremental consent works.
- [[Tokens]] — refresh cadence, failure modes, revocation.
- [[Connection UI]] — Livewire, React, Vue components, and the status endpoint.
- [[API Reference]] — the full public surface: `Google` facade, `google()` helper, contracts, and models.

---
Continue to [[Installation]] →
