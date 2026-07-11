---
title: Google Cloud Setup
---

# Google Cloud Setup

The package needs a Google Cloud project with an OAuth 2.0 client. This page walks through every step.

## 1. Create or select a project

Open the [Google Cloud Console](https://console.cloud.google.com/) and pick (or create) a project from the top-of-page selector. All the credentials you're about to create live inside this project.

## 2. Configure the OAuth consent screen

Navigate to **APIs & Services → OAuth consent screen**.

- **User type**: Choose **External** unless every user has a Workspace account on the same domain (in which case **Internal** is fine and skips verification).
- **App information**: Fill in app name, user support email, and developer contact email. These are shown to the user on the consent screen.
- **App domain**: Optional but strongly recommended — home page, privacy policy, terms of service. Google requires these before you can publish an external app.
- **Authorized domains**: Add the domain(s) your redirect URI lives under (e.g. `your-app.com`, `your-app.test`).

## 3. Add the scopes your app needs

Under **Scopes**, click **Add or remove scopes** and check every scope your installed service packages require.

Each service package documents the exact scopes it registers via [[Scopes|`ap.google.scopes`]]. Add all of them here — the consent screen only shows scopes it recognizes, so a missing scope will make Google reject the authorize request at runtime.

The base package always adds these three baseline scopes (you don't need to add them yourself — Google always allows OpenID Connect scopes):

- `openid`
- `https://www.googleapis.com/auth/userinfo.email`
- `https://www.googleapis.com/auth/userinfo.profile`

## 4. Add test users (Testing mode only)

While the app is in **Testing** mode, only accounts you list under **Test users** can complete the flow. Add every developer account and any beta testers. When you're ready for real users, hit **Publish app** — for external apps this triggers Google's verification review if any of your scopes are marked "sensitive" or "restricted".

## 5. Enable the APIs your service packages depend on

Under **APIs & Services → Library**, search for and enable each API your service packages call:

| Service package | API to enable |
|---|---|
| `artisanpack-ui/analytics-google` | Google Analytics Data API, Google Analytics Admin API |
| `artisanpack-ui/google-search-console` | Google Search Console API |
| `artisanpack-ui/google-tag-manager` | Tag Manager API |

Enabling the API just flips a switch; billing is only relevant for APIs with quota above the free tier.

## 6. Create OAuth client credentials

Go to **APIs & Services → Credentials → Create Credentials → OAuth client ID**.

- **Application type**: **Web application**.
- **Name**: Whatever helps you find it later ("ArtisanPack UI Google — production").
- **Authorized JavaScript origins**: Optional. Only relevant for browser-side OAuth flows (this package uses server-side, so you can leave it blank).
- **Authorized redirect URIs**: Add **exactly** the URL Google will redirect back to after consent. For the default configuration that's:

  ```
  https://your-app.test/google/auth/callback
  ```

  If you customize `google.routes.prefix`, update this to match. You can add multiple entries — one per environment (localhost, staging, production).

Hit **Create**. Google shows you the **Client ID** and **Client secret**.

## 7. Store the credentials in your app

Depending on your [[Credential Drivers|driver]]:

### `config` driver (default)

Add to `.env`:

```env
GOOGLE_CLIENT_ID=1234567890-abcdef.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxxxxxxxxxx
GOOGLE_REDIRECT_URI=https://your-app.test/google/auth/callback
```

### `database` driver

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config()->save( [
    'client_id'     => '1234567890-abcdef.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-xxxxxxxxxxxxxxxxxxxx',
    'redirect_uri'  => 'https://your-app.test/google/auth/callback',
] );
```

### `cms` driver

Save through the CMS Settings UI, or programmatically:

```php
apUpdateSetting( 'artisanpack_google_client_id', '1234567890-abcdef.apps.googleusercontent.com' );
apUpdateSetting( 'artisanpack_google_client_secret', 'GOCSPX-xxxxxxxxxxxxxxxxxxxx' ); // sanitize callback encrypts before write
apUpdateSetting( 'artisanpack_google_redirect_uri', 'https://your-app.test/google/auth/callback' );
```

## 8. Test the flow

Log in as a test user (or any authenticated user once the app is published) and hit `route('google.auth.connect')`. You should see Google's consent screen listing every scope your service packages registered, then land back on `google.routes.redirect_after_connect` with a fresh `google_connections` row.

## Common gotchas

- **`redirect_uri_mismatch`**: The value stored in your credential driver must exactly match one of the "Authorized redirect URIs" on the OAuth client, character-for-character. `http` vs. `https`, trailing slash, port — all significant.
- **`invalid_scope`**: A scope your service package registered isn't checked on the consent screen, or the corresponding API isn't enabled. Add it in **OAuth consent screen → Scopes** and enable the API under **Library**.
- **`access_denied` after publish**: If any of your scopes are "sensitive" or "restricted", the app must complete Google's verification review before non-test-user accounts can consent. In the interim, add users to the test-user list.
- **App still in "Testing" mode after months**: External apps can stay in Testing indefinitely, but refresh tokens issued in Testing mode are valid for **7 days**. Publish to get non-expiring refresh tokens.
- **Refresh token missing after first consent**: Google only returns a `refresh_token` on the first consent. If you're testing repeatedly with the same account, revoke access at [Google Account permissions](https://myaccount.google.com/permissions) between runs, or you'll get access tokens with no refresh token attached.
