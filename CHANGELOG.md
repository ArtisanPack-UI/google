# ArtisanPack UI Google Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Dropped Laravel 10 support.** `illuminate/*` constraints narrowed to `^11.0|^12.0|^13.0`. The package's Eloquent models use the `casts()` method (Laravel 11+), which silently no-ops on Laravel 10, so the previously advertised `^10.0` was never actually usable ([#14](https://github.com/ArtisanPack-UI/google/issues/14)).

## [1.0.0] - 2026-07-10

Initial release of the shared Google OAuth2 authentication, token storage/refresh, and scope management layer that powers every ArtisanPack UI Google service integration.

### Added

- **OAuth2 authorization-code + PKCE flow** — `OAuthManager` builds the Google consent URL, verifies `state` with `hash_equals`, exchanges the code, and extracts identity claims from the returned `id_token`. Five web routes ship mounted under the configurable `google/auth` prefix: `connect`, `callback`, `reauthorize`, `disconnect`, and `status`.
- **Encrypted token storage** — `GoogleConnection` Eloquent model with `encrypted` casts on `access_token` and `refresh_token`, JSON `scopes`, `datetime` `expires_at`, `connected` / `disconnected` status, and `disconnect_reason` for post-mortem visibility. One row per user, uniquely indexed on `user_id`.
- **Transparent token refresh** — `TokenManager::getValidAccessToken()` refreshes proactively 60 seconds before the stored token expires. Auto-marks the connection disconnected on `invalid_grant` or missing refresh token; preserves existing refresh tokens across incremental-consent regrants.
- **Scope registry** — `ScopeRegistry` unions a baseline (`openid`, `userinfo.email`, `userinfo.profile`) with imperative registrations and every callback hooked to the `ap.google.scopes` filter. Supports incremental consent via `missing()` and `hasAllRequired()` — service packages register scopes once, users see a single consent screen for the union.
- **Three credential drivers** behind the `ConfigurationRepository` contract:
  - `config` (default) — reads from `config/google.php` / `.env`. Read-only.
  - `database` — stores in the `google_configurations` table; client secret encrypted with `APP_KEY`.
  - `cms` — delegates to the `artisanpack-ui/cms-framework` Settings module; client secret encrypted at sanitize time so operator writes through the CMS UI and programmatic writes both persist the same ciphertext shape.
- **Connection-management UI** — three interchangeable components backed by the same routes and JSON status endpoint:
  - Livewire — `<livewire:google-connection-manager />`, auto-registered when Livewire is installed.
  - React — source under `resources/js/react/`, plus a shared TypeScript API client and label bundle for i18n.
  - Vue 3 — source under `resources/js/vue/`, mirroring the React component.
  - All three delegate view-model rules to `ConnectionState` so they can never drift.
- **Publishable assets** — `google-config`, `google-migrations`, `google-views`, `google-js` publish tags.
- **Comprehensive documentation** under `/docs` covering installation, credential drivers, OAuth flow, scopes, tokens, connection model, connection UI, API reference, testing patterns, and FAQ.
- **Laravel 10, 11, 12, and 13 support** via widened `illuminate/*` constraints.
- **CI matrix** — PHP/Laravel test matrix, lint, and release workflows via `.github/workflows/`.

### Security

- CSRF-defended OAuth flow via cryptographically random `state` (40 chars) compared with `hash_equals`.
- PKCE with S256 challenge on every authorize request — intercepted redirects can't be exchanged without the verifier stashed server-side.
- `access_type=offline` + `prompt=consent` on every authorize request to guarantee a fresh refresh token.
- All session values used by the flow (`state`, `code_verifier`, `user_id`) are pulled and cleared on callback, so replays fail closed.
- Client secret encryption in the `database` and `cms` drivers; `APP_KEY` rotation without re-encryption logs a warning and reports the driver as unconfigured rather than silently 500ing.
