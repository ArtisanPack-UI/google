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

## Broker mode

*Since 1.2.0.* See [Broker Mode](Broker-Mode).

| Variable | Type | Default | Read by |
|---|---|---|---|
| `GOOGLE_OAUTH_MODE` | `direct` \| `broker` | `direct` | `OAuthManager`, `TokenManager`, `Google::usesBroker()`. |
| `GOOGLE_BROKER_URL` | URL (HTTPS) | `null` | `BrokerCredentials::fromConfig()` (via the `ap.google.broker.credentials` filter). |
| `GOOGLE_BROKER_SITE_ID` | string | `null` | Same. |
| `GOOGLE_BROKER_SITE_SECRET` | string | `null` | Same. Secret — do not commit. |
| `GOOGLE_BROKER_RETURN_URL` | URL | `null` → `route('google.auth.callback')` | `OAuthManager` when building the broker `/authorize` link. |

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

Broker mode — no Google client credentials on the site at all:

```env
APP_URL=https://your-app.test
APP_KEY=base64:...

GOOGLE_OAUTH_MODE=broker
GOOGLE_BROKER_URL=https://broker.example.com
GOOGLE_BROKER_SITE_ID=site_123
GOOGLE_BROKER_SITE_SECRET=42|plain-secret-from-the-broker
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

See [Configuration](Installation-Configuration) for the full reference.
