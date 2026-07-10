---
title: Home
---

# ArtisanPack UI Google Documentation

Welcome to the documentation for **ArtisanPack UI Google** — the shared Google OAuth2 authentication, token storage/refresh, and scope management layer that powers every ArtisanPack UI Google service integration.

Service packages like [`artisanpack-ui/analytics-google`](https://github.com/ArtisanPack-UI/analytics-google), [`artisanpack-ui/google-search-console`](https://github.com/ArtisanPack-UI/google-search-console), and [`artisanpack-ui/google-tag-manager`](https://github.com/ArtisanPack-UI/google-tag-manager) sit on top of this package — they contribute the scopes they need, then call the shared token manager to make authenticated API calls. They never handle OAuth themselves.

Use the navigation below to explore topics. Links use the GitLab wiki page style, so you can jump between pages like [[Getting Started]] or [[OAuth Flow]].

- [[Getting Started]]
- [[Installation]]
- [[Credential Drivers]]
- [[OAuth Flow]]
- [[Scopes]]
- [[Tokens]]
- [[Connection Model]]
- [[Connection UI]]
- [[API Reference]]
- [[Testing]]
- [[FAQ]]
- [[Changelog]]
- [[Contributing]]

If you're new here, start with [[Getting Started]].

## What this package does

`artisanpack-ui/google` owns the shared plumbing that every ArtisanPack UI Google integration sits on top of. It provides:

- **OAuth2 authorization-code + PKCE flow** — builds the consent URL, exchanges the callback code, extracts the user's Google identity from the returned `id_token`.
- **Encrypted token storage** on a per-user `google_connections` model, with transparent refresh via the [[Tokens|token manager]].
- A **scope registry** that lets any installed service package contribute the scopes it needs. Consent covers the union so users only see one screen.
- **Credential storage drivers** ([[Credential Drivers|config, database, or CMS]]) so credentials can live wherever a project already stores its secrets.
- **Connection-management UI** for Livewire, React, and Vue — all backed by the same routes and JSON status endpoint.

## What this package does not do

- It does **not** call Google APIs. That is the responsibility of the service packages (Analytics, Search Console, Tag Manager, …).
- It does **not** ship any per-service enable/disable toggles. Connection management is deliberately scope-limited to connect / disconnect / status.
- It does **not** perform remote token revocation on disconnect — the flow marks the local connection disconnected. If you need to revoke with Google, hit `https://oauth2.googleapis.com/revoke` from your own code.
