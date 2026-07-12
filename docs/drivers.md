---
title: Credential Drivers
---

# Credential Drivers

`artisanpack-ui/google` stores **OAuth tokens** (`access_token`, `refresh_token`) in the `google_connections` table, always. The choice of driver only affects **app credentials** — the `client_id`, `client_secret`, and `redirect_uri` used to build the OAuth request itself.

Three drivers ship in the box; pick the one that matches how your project stores secrets:

| Driver | Storage | Writable? | Best for |
|---|---|---|---|
| [`config`](Drivers-Config) (default) | `config/google.php` / `.env` | No | Single-tenant apps where credentials belong in the deploy pipeline. |
| [`database`](Drivers-Database) | `google_configurations` table (client_secret encrypted) | Yes | Multi-tenant apps, admin-UI-managed credentials, credential rotation without a deploy. |
| [`cms`](Drivers-CMS) | CMS framework Settings module (client_secret encrypted) | Yes | Projects already using `artisanpack-ui/cms-framework` — credentials live alongside every other site-level setting. |

## Selecting a driver

Set the driver via `.env`:

```env
GOOGLE_CONFIG_DRIVER=database
```

Or in `config/google.php`:

```php
'driver' => 'database',
```

The service provider re-reads `config('google.driver')` every time it resolves the `ConfigurationRepository` binding, so runtime overrides work — useful for tests, multi-tenant middleware that swaps drivers per-tenant, etc.

## The contract

Every driver implements `ArtisanPackUI\Google\Contracts\ConfigurationRepository`:

```php
interface ConfigurationRepository
{
    public function getClientId(): ?string;
    public function getClientSecret(): ?string;
    public function getRedirectUri(): ?string;
    public function save( array $credentials ): void;
    public function isConfigured(): bool;
}
```

Resolve it from the container:

```php
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;

$config = app( ConfigurationRepository::class );

if ( $config->isConfigured() ) {
    // safe to build authorize URLs
}
```

Or via the facade:

```php
use ArtisanPackUI\Google\Facades\Google;

Google::config()->save( [
    'client_id'     => '...',
    'client_secret' => '...',
    'redirect_uri'  => '...',
] );
```

## Writing your own driver

Drivers are trivially replaceable — implement the four-method contract and re-bind the `ConfigurationRepository`:

```php
// app/Google/VaultDriver.php
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;

class VaultDriver implements ConfigurationRepository
{
    // ...
}

// AppServiceProvider::register()
$this->app->bind( ConfigurationRepository::class, VaultDriver::class );
```

Because `GoogleServiceProvider::register()` uses `$this->app->bind()` (not `singleton`), your override wins as long as you register it after the package provider — which is the default order for app providers.

## Deeper topics

- [`config` driver](Drivers-Config) — env / config-file reader.
- [`database` driver](Drivers-Database) — encrypted `google_configurations` row.
- [`cms` driver](Drivers-CMS) — Settings-module integration and sanitize-time encryption.

---
Continue to [OAuth Flow](Oauth) →
