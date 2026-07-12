---
title: Requirements
---

# Requirements

## PHP

- **PHP 8.2 or newer.** The package uses constructor property promotion, readonly properties, and typed enums throughout.

## Laravel

- **Laravel 10.x, 11.x, 12.x, or 13.x.** The package requires `illuminate/support`, `illuminate/database`, `illuminate/http`, `illuminate/routing`, and `illuminate/encryption` on the matching majors.

The service provider registers with either the classic `config/app.php` providers array (Laravel 10) or `bootstrap/providers.php` (Laravel 11+). Laravel's package discovery handles it automatically.

## HTTP client

- **Guzzle 7.5+** (`guzzlehttp/guzzle`). Used by the OAuth code exchange and token refresh calls. Laravel apps almost always have Guzzle installed already; if you're running on a stripped-down install, add it explicitly.

## Required ArtisanPack packages

- **`artisanpack-ui/core` ^1.0** — pulled in transitively.
- **`artisanpack-ui/hooks` ^1.2** — the [scope registry](Scopes) uses the `ap.google.scopes` filter hook to collect scopes from service packages.

## Optional peer packages

| Package | Version | What it enables |
|---|---|---|
| [`livewire/livewire`](https://livewire.laravel.com/) | `^3.6` | The `<livewire:google-connection-manager />` Livewire component (see [Connection UI](Connection-UI)). The React and Vue equivalents do not require Livewire. |
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | any | The `cms` credential driver — stores credentials via the framework's Settings module (see [cms driver](Drivers#cms)). |

Neither is required. The base package boots and works without them; the CMS driver simply refuses to save if the framework isn't installed, and the Livewire component is only registered when Livewire's class is autoloadable.

## Database

The package ships two tables:

- `google_configurations` (only used by the `database` credential driver)
- `google_connections` (used by every driver — tokens always live in the database)

Any Laravel-supported connection works. If you migrate on a fresh install and later switch drivers, the unused table stays empty — leaving it in place is fine.

## Encryption

The package uses Laravel's `Encrypter` (`APP_KEY`) to encrypt:

- The `client_secret` column when using the `database` or `cms` credential drivers.
- The `access_token` and `refresh_token` columns on every `google_connections` row, via Eloquent's `encrypted` cast.

**Rotating `APP_KEY` without re-encrypting** the stored ciphertext will silently invalidate every stored connection and stored client secret. The credential drivers log a warning and treat the row as unconfigured; the `google_connections` cast will fail to decrypt, which surfaces as a `DecryptException` when the token manager runs. Plan a re-encryption pass alongside key rotations.

## Session driver

The [OAuth Flow](Oauth) uses the session to persist the CSRF `state`, PKCE `code_verifier`, and user id between the redirect to Google and the callback. Any Laravel session driver works — cookie, file, database, redis. Just make sure sessions are actually enabled on the route group that mounts the four package routes (the default `web` middleware satisfies this).

## Frontend runtime (JS components only)

The React and Vue components are shipped as source (`.tsx` / `.vue`). If you use them:

- **React**: 18+ (uses `useEffect`, `useState`, standard hooks).
- **Vue**: 3+ (uses the Composition API with `<script setup>`).
- A bundler (Vite, Webpack, Turbopack, …) that can compile TS/TSX/Vue files.

The base package works without a frontend build — Livewire renders the connection UI server-side.
