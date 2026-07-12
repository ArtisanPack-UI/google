---
title: CMS Driver
---

# `cms` Driver

Stores app credentials via the [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) Settings module, so Google credentials live alongside every other site-level setting.

**Requires `artisanpack-ui/cms-framework`** to be installed. If it isn't, the driver's setting keys are never registered and reads return `null` — you'll get a clean "not configured" state instead of a hard boot error.

## Selecting

```env
GOOGLE_CONFIG_DRIVER=cms
```

## Setting keys

The driver reads and writes three keys through `apGetSetting()` / `apUpdateSetting()`:

| Constant | Setting key |
|---|---|
| `CmsSettingsDriver::KEY_CLIENT_ID` | `artisanpack_google_client_id` |
| `CmsSettingsDriver::KEY_CLIENT_SECRET` | `artisanpack_google_client_secret` |
| `CmsSettingsDriver::KEY_REDIRECT_URI` | `artisanpack_google_redirect_uri` |

These keys are registered with the CMS framework at `booted()` time (inside `GoogleServiceProvider::registerCmsSettings()`), so any Settings UI the project exposes will surface them automatically.

## Sanitize-time encryption

Unlike the `database` driver, encryption happens in the **sanitize callback** the service provider registers with the CMS framework — not inside `save()`:

```php
$encryptSecret = static function ( mixed $value ) use ( $encrypter, $trim ): ?string {
    $trimmed = $trim( $value );
    if ( null === $trimmed ) {
        return null;
    }

    return $encrypter->encryptString( $trimmed );
};

apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, null, $encryptSecret );
```

**Why**: two paths write the client secret:

1. `Google::config()->save()` → driver → `apUpdateSetting()`
2. Operator saves through the CMS Settings UI → `apUpdateSetting()` directly

Owning encryption inside the sanitize callback means **both paths write the same ciphertext shape**, so the read-side decryption always sees an encrypted value regardless of who wrote it. The driver's `save()` therefore passes plaintext through — it doesn't encrypt again.

## Reading credentials

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config()->getClientId();
Google::config()->getClientSecret();  // decrypted plaintext
Google::config()->getRedirectUri();
Google::config()->isConfigured();
```

Values are cached per-request. Call `flush()` on the driver instance if you need to force a re-read (primarily for tests):

```php
app( \ArtisanPackUI\Google\Configuration\CmsSettingsDriver::class )->flush();
```

## Saving credentials

Programmatic:

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config()->save( [
    'client_id'     => '...',
    'client_secret' => '...',
    'redirect_uri'  => '...',
] );
```

Via the CMS Settings UI: the sanitize callback ensures whatever the operator types is encrypted before storage.

Direct calls to `apUpdateSetting()` also work — same sanitize callback, same result:

```php
apUpdateSetting( 'artisanpack_google_client_id', '...' );
apUpdateSetting( 'artisanpack_google_client_secret', '...' );
apUpdateSetting( 'artisanpack_google_redirect_uri', '...' );
```

## `APP_KEY` rotation

Same story as the [`database` driver](Drivers-Database): rotating `APP_KEY` without re-encrypting the stored setting invalidates the client secret. The driver logs a warning and treats the row as unconfigured:

```
artisanpack-ui/google: failed to decrypt CMS-stored client_secret; treating as unconfigured.
Was APP_KEY rotated without re-encrypting the setting?
```

Re-save via `Google::config()->save()` after rotating.

## When to use it

- Projects already built on `artisanpack-ui/cms-framework`.
- You want Google credentials editable through the same admin surface as every other site setting.
- Multi-site CMS installations where each site has its own Google client.

## When not to use it

- CMS framework not installed — use `config` or `database` instead.
- You want credentials completely hidden from admin operators — the CMS Settings UI will show at least the redirect URI and client ID in plaintext.

## Boot order note

The setting registration runs inside `$this->app->booted()`, not directly in `boot()`. Reason: the CMS-framework helpers (`apRegisterSetting`, `apGetSetting`, `apUpdateSetting`) are declared from that package's own `boot()` method, and Laravel's provider boot order is not deterministic. If `GoogleServiceProvider` happens to boot first, registering directly from its `boot()` would silently skip the three keys — the CMS Settings UI would never expose them. The `booted()` deferral guarantees the helpers exist by the time the registration runs.
