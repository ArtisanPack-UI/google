---
title: Configuration Reference
---

# Configuration Reference

Complete reference for `config/google.php`. Publish with:

```bash
php artisan vendor:publish --tag=google-config
```

The published file is the source of truth — this page mirrors it and explains each key.

## Sections

- [App credentials](#app-credentials)
- [Configuration driver](#configuration-driver)
- [OAuth mode](#oauth-mode)
- [OAuth broker](#oauth-broker)
- [OAuth endpoints](#oauth-endpoints)
- [Routes](#routes)
- [User model](#user-model)

## App credentials

```php
'client_id'     => env( 'GOOGLE_CLIENT_ID' ),
'client_secret' => env( 'GOOGLE_CLIENT_SECRET' ),
'redirect_uri'  => env( 'GOOGLE_REDIRECT_URI' ),
```

Only read by the [config driver](Drivers-Config). The `database` and `cms` drivers ignore these values and pull from their own storage.

- **`client_id`** — Google OAuth 2.0 Client ID (`*.apps.googleusercontent.com`).
- **`client_secret`** — Client secret from the Cloud Console. Treat as a secret; do not commit.
- **`redirect_uri`** — Must exactly match one of the "Authorized redirect URIs" configured on the OAuth client. The package uses this value in both the initial authorize call and the code exchange — a mismatch causes Google to reject the request with `redirect_uri_mismatch`.

## Configuration driver

```php
'driver' => env( 'GOOGLE_CONFIG_DRIVER', 'config' ),
```

Which driver backs the `ConfigurationRepository` contract. Supported values:

| Value | Where credentials live | Writable? |
|---|---|---|
| `config` (default) | `config/google.php` / `.env` | No — `save()` throws. |
| `database` | `google_configurations` table (client_secret encrypted) | Yes. |
| `cms` | CMS Settings module (client_secret encrypted) | Yes; requires `artisanpack-ui/cms-framework`. |

OAuth **tokens** are always stored in the `google_connections` table regardless of this setting. This key controls credential (client ID / secret / redirect URI) storage only.

See [Credential Drivers](Drivers) for the full comparison and switching guidance.

## OAuth mode

*Since 1.2.0.*

```php
'mode' => env( 'GOOGLE_OAUTH_MODE', 'direct' ),
```

- **`direct`** (default) — the app talks to Google with its own client ID and secret from the credential driver.
- **`broker`** — connect, callback and refresh run through an OAuth broker using the `broker` settings below. The site never holds a Google client secret, and the credential driver is not used for the OAuth flow.

See [Broker Mode](Broker-Mode).

## OAuth broker

*Since 1.2.0.* Only read when `mode` is `broker`.

```php
'broker' => [
    'url'         => env( 'GOOGLE_BROKER_URL' ),
    'site_id'     => env( 'GOOGLE_BROKER_SITE_ID' ),
    'site_secret' => env( 'GOOGLE_BROKER_SITE_SECRET' ),
    'return_url'  => env( 'GOOGLE_BROKER_RETURN_URL' ),
],
```

- **`url`** — broker base URL. Must be HTTPS; plain HTTP is accepted only for `localhost`, `*.localhost`, `*.test`, and loopback hosts.
- **`site_id`** — this site's ID at the broker.
- **`site_secret`** — this site's secret (`{id}|{plain}`). Sent as the bearer token and used to sign `/authorize` links. Treat as a secret; do not commit.
- **`return_url`** — where the broker sends the browser back. Defaults to `route('google.auth.callback')`; must be on the URL the site registered with the broker. Required when `routes.enabled` is `false`.

`url`, `site_id`, and `site_secret` pass through the `ap.google.broker.credentials` filter, so a host can supply them at runtime — see [Broker Mode → Supplying credentials at runtime](Broker-Mode#supplying-credentials-at-runtime).

## OAuth endpoints

```php
'endpoints' => [
    'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token'     => 'https://oauth2.googleapis.com/token',
    'revoke'    => 'https://oauth2.googleapis.com/revoke',
],
```

Google's OAuth endpoints. Overridable for tests — point them at a mock in your `TestCase::setUp()`:

```php
config( [ 'google.endpoints.token' => 'http://localhost/mock/token' ] );
```

The `revoke` endpoint is included for callers who want to hit Google's revocation endpoint directly. The built-in disconnect flow is local-only — see [OAuth Flow#Disconnect](Oauth#disconnect).

## Routes

```php
'routes' => [
    'enabled'                => true,
    'prefix'                 => 'google/auth',
    'middleware'             => [ 'web' ],
    'redirect_after_connect' => '/',
    'redirect_after_error'   => '/',
],
```

- **`enabled`** — Set `false` to skip registering the five built-in routes. Use when your app mounts a custom controller on its own routes but still wants the manager services.
- **`prefix`** — Route prefix for `connect`, `callback`, `reauthorize`, `disconnect`, and `status`. When you change this, remember to update the redirect URI on the Google OAuth client.
- **`middleware`** — Middleware applied to the group. Default is `['web']`; add `'auth'` to require an authenticated user (the controller also `abort( 401 )`s on unauthenticated access, but adding `auth` gives you the login redirect for free).
- **`redirect_after_connect`** — Where to send the user after a successful connect, disconnect, or reauthorize. Either a path (`'/settings/integrations'`) or a named route (`'settings.integrations'`). Named routes are preferred — the controller checks `Route::has()` first and falls back to a raw redirect if not found.
- **`redirect_after_error`** — Where to send the user when the OAuth flow errors (Google returned an error, state mismatch, code exchange failed, etc.). Same shape as `redirect_after_connect`. The error message is flashed to the session as `google.error`; in broker mode, a trusted license renewal URL is also flashed as `google.renew_url`.

Both redirect keys are read on every callback, so you can safely swap them per-tenant with a runtime `config()->set()`.

## User model

```php
'user_model' => env( 'GOOGLE_USER_MODEL', 'App\\Models\\User' ),
```

The Eloquent model that `GoogleConnection` belongs to. Only used by the `user()` relationship on the connection model — the OAuth flow uses `Auth::user()->getAuthIdentifier()`, so as long as your guarded user model matches, this key only matters if you traverse `$connection->user`.

Handy for multi-model apps (e.g., a `Tenant` model that "connects" to Google alongside your `User`).

## Related pages

- [Environment variables](Installation-Environment-Variables) — every env var, in one table.
- [Credential Drivers](Drivers) — driver behavior in depth.
- [OAuth Flow](Oauth) — how the routes are wired end-to-end.
