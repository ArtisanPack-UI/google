---
title: Changelog
---

# Changelog

The authoritative changelog lives at `CHANGELOG.md` in the package root. This page mirrors it.

## Unreleased

- Initial scaffold from the ArtisanPack UI package blueprint.
- Adds `illuminate/support` support for Laravel 10, 11, 12, and 13.

## 1.0.0

Initial release. Includes:

- **OAuth2 authorization-code + PKCE flow** — `OAuthManager` and the `google/auth/*` route group.
- **Encrypted token storage** — `GoogleConnection` model with encrypted `access_token` and `refresh_token` casts, JSON `scopes`, `datetime` `expires_at`.
- **Token manager** — `TokenManager::getValidAccessToken()` refreshes proactively 60 seconds before expiry; auto-disconnects on `invalid_grant` or missing refresh token.
- **Scope registry** — `ScopeRegistry` unions baseline + imperative + filter-hook contributions via `ap.google.scopes`. Supports incremental consent via `missing()` and `hasAllRequired()`.
- **Three credential drivers** — `config` (default), `database` (with encrypted client_secret), `cms` (via `artisanpack-ui/cms-framework` Settings).
- **Connection UI** — Livewire, React, and Vue components, all backed by the shared `ConnectionState` view model and `GET /google/auth/status` JSON endpoint.
- **Publishable assets** — `google-config`, `google-migrations`, `google-views`, `google-js` publish tags.
