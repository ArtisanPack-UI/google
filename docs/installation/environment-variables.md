---
title: Environment Variables
---

# Environment Variables

Every env var the package reads, in one place.

## Core

| Variable | Type | Default | Read by |
|---|---|---|---|
| `GOOGLE_CLIENT_ID` | string | `null` | `config` driver only. |
| `GOOGLE_CLIENT_SECRET` | string | `null` | `config` driver only. |
| `GOOGLE_REDIRECT_URI` | URL | `null` | `config` driver only. |
| `GOOGLE_CONFIG_DRIVER` | `config` \| `database` \| `cms` | `config` | Service provider. Selects which credential driver backs the `ConfigurationRepository` binding. |
| `GOOGLE_USER_MODEL` | class-string | `App\Models\User` | `GoogleConnection::user()`. Rarely needed. |

## Framework env vars this package leans on

| Variable | Why it matters |
|---|---|
| `APP_KEY` | Used to encrypt the `access_token` / `refresh_token` on every `google_connections` row, plus the stored `client_secret` when using the `database` or `cms` credential drivers. **Rotate carefully** — re-encrypt existing rows during the same migration. |
| `SESSION_DRIVER` | The OAuth flow persists `state`, `code_verifier`, and `user_id` in the session across the redirect to Google. Any Laravel driver works. |
| `APP_URL` | Not read directly, but if your app builds `GOOGLE_REDIRECT_URI` from `APP_URL`, keep them consistent with the redirect URI registered on the Google OAuth client. |

## Example `.env`

Minimal single-tenant config, using the default `config` driver:

```env
APP_URL=https://your-app.test
APP_KEY=base64:...

GOOGLE_CONFIG_DRIVER=config
GOOGLE_CLIENT_ID=1234567890-abcdef.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxxxxxxxxxx
GOOGLE_REDIRECT_URI=https://your-app.test/google/auth/callback
```

Multi-tenant or admin-managed credentials, using the `database` driver — no client credentials in `.env`:

```env
APP_URL=https://your-app.test
APP_KEY=base64:...

GOOGLE_CONFIG_DRIVER=database
```

CMS-managed credentials, if `artisanpack-ui/cms-framework` is installed:

```env
GOOGLE_CONFIG_DRIVER=cms
```

## Not env-backed

These keys have no env fallback and must be set in `config/google.php` if you want to override them:

- `google.endpoints.authorize`
- `google.endpoints.token`
- `google.endpoints.revoke`
- `google.routes.enabled`
- `google.routes.prefix`
- `google.routes.middleware`
- `google.routes.redirect_after_connect`
- `google.routes.redirect_after_error`

See [[Installation/Configuration|Configuration]] for the full reference.
