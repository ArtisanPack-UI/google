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
- [OAuth endpoints](#oauth-endpoints)
- [Routes](#routes)
- [User model](#user-model)

## App credentials

```php
'client_id'     => env( 'GOOGLE_CLIENT_ID' ),
'client_secret' => env( 'GOOGLE_CLIENT_SECRET' ),
'redirect_uri'  => env( 'GOOGLE_REDIRECT_URI' ),
```

Only read by the [config driver](Drivers#config). The `database` and `cms` drivers ignore these values and pull from their own storage.

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

- **`enabled`** — Set `false` to skip registering the four built-in routes. Use when your app mounts a custom controller on its own routes but still wants the manager services.
- **`prefix`** — Route prefix for `connect`, `callback`, `reauthorize`, `disconnect`, and `status`. When you change this, remember to update the redirect URI on the Google OAuth client.
- **`middleware`** — Middleware applied to the group. Default is `['web']`; add `'auth'` to require an authenticated user (the controller also `abort( 401 )`s on unauthenticated access, but adding `auth` gives you the login redirect for free).
- **`redirect_after_connect`** — Where to send the user after a successful connect, disconnect, or reauthorize. Either a path (`'/settings/integrations'`) or a named route (`'settings.integrations'`). Named routes are preferred — the controller checks `Route::has()` first and falls back to a raw redirect if not found.
- **`redirect_after_error`** — Where to send the user when the OAuth flow errors (Google returned an error, state mismatch, code exchange failed, etc.). Same shape as `redirect_after_connect`. The error message is flashed to the session as `google.error`.

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
