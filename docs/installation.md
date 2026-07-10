---
title: Installation
---

# Installation

## Install via Composer

```bash
composer require artisanpack-ui/google
```

The package auto-registers via Laravel's package discovery:

- **Service provider**: `ArtisanPackUI\Google\GoogleServiceProvider`
- **Facade alias**: `Google` (`ArtisanPackUI\Google\Facades\Google`)

No manual changes to `config/app.php` are required in a standard Laravel app.

## Publish the migrations

```bash
php artisan vendor:publish --tag=google-migrations
php artisan migrate
```

This creates two tables:

- `google_configurations` — used by the [[Credential Drivers#database|database driver]] to store OAuth client credentials. Unused by the `config` and `cms` drivers.
- `google_connections` — the per-user connection row (encrypted access + refresh tokens, granted scopes, expiry, status).

See [[Connection Model]] for a full column reference.

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=google-config
```

Publishes `config/google.php`. Override routes, drivers, endpoints, or the user model here. Full reference: [[Installation/Configuration|Configuration]].

## Publish the views (optional)

```bash
php artisan vendor:publish --tag=google-views
```

Copies the Livewire component's Blade views to `resources/views/vendor/google/`. Only needed if you want to customize the connect / disconnect / status markup. See [[Connection UI]].

## Publish the JS components (optional)

```bash
php artisan vendor:publish --tag=google-js
```

Copies the React and Vue `ConnectionManager` sources to `resources/js/vendor/google/`. Skip this step if you'd rather point your bundler at the package's `resources/js/` directly. See [[Connection UI]].

## Google Cloud Console setup

Before a user can connect, you need an OAuth 2.0 client from Google:

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and create (or select) a project.
2. Navigate to **APIs & Services → OAuth consent screen**. Choose **External** unless every user has a Workspace account on the same domain, then fill in the app name, support email, and developer contact.
3. Under **Scopes**, add every scope your installed service packages require. Consent screens for external apps in "Testing" mode are limited to added test users; publish the app when you're ready for production traffic.
4. Enable the APIs your service packages depend on (Analytics Data API, Search Console API, Tag Manager API, …) under **APIs & Services → Library**.
5. Go to **APIs & Services → Credentials → Create Credentials → OAuth client ID**.
   - Application type: **Web application**
   - Authorized redirect URI: `https://your-app.test/google/auth/callback` (adjust prefix if you change `google.routes.prefix`).
6. Copy the generated **Client ID** and **Client secret**.
7. Save them via whichever [[Credential Drivers|credential driver]] you've chosen.

## Store your credentials

Choose a driver by setting `GOOGLE_CONFIG_DRIVER` (default `config`):

```env
GOOGLE_CONFIG_DRIVER=config      # default; reads .env / config/google.php
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://your-app.test/google/auth/callback
```

Or switch to a writable driver — see [[Credential Drivers]].

## Apply route middleware (optional)

The package registers four web routes under `google.routes.prefix` (default `/google/auth`) with `web` middleware. Adjust if your app needs auth-required or tenant-scoped routes:

```php
// config/google.php
'routes' => [
    'middleware' => [ 'web', 'auth' ],
    // ...
],
```

Route reference: [[OAuth Flow#Routes|OAuth Flow]].

## Verify the install

Run:

```bash
php artisan route:list --path=google
```

You should see:

```
GET   google/auth/connect      google.auth.connect
GET   google/auth/callback     google.auth.callback
GET   google/auth/reauthorize  google.auth.reauthorize
GET   google/auth/status       google.auth.status
POST  google/auth/disconnect   google.auth.disconnect
```

## Deeper topics

- [[Installation/Requirements|Requirements]] — PHP, Laravel, and peer-package versions in full detail.
- [[Installation/Configuration|Configuration]] — full `config/google.php` reference.
- [[Installation/Environment Variables|Environment variables]] — every env var the package reads.
- [[Installation/Google Cloud Setup|Google Cloud setup]] — the Cloud Console walkthrough, with screenshots-worth-of-notes on scope publishing, verification, and test users.

---
Continue to [[Credential Drivers]] →
