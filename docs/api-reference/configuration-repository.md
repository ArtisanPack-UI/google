---
title: ConfigurationRepository
---

# `ConfigurationRepository`

`ArtisanPackUI\Google\Contracts\ConfigurationRepository` is the contract every credential driver implements. See [Credential Drivers](Drivers) for driver behavior and selection.

## The contract

```php
namespace ArtisanPackUI\Google\Contracts;

interface ConfigurationRepository
{
    public function getClientId(): ?string;
    public function getClientSecret(): ?string;
    public function getRedirectUri(): ?string;
    public function save( array $credentials ): void;
    public function isConfigured(): bool;
}
```

## Methods

### `getClientId(): ?string`

The OAuth 2.0 client ID. Returns `null` when unconfigured.

### `getClientSecret(): ?string`

The OAuth 2.0 client secret. Returns `null` when unconfigured. **Always returns plaintext** — drivers that store ciphertext decrypt before returning.

### `getRedirectUri(): ?string`

The redirect URI. Must match one of the "Authorized redirect URIs" on the Google OAuth client.

### `save( array $credentials ): void`

Persist a full credential set. `$credentials` is an associative array with keys `client_id`, `client_secret`, `redirect_uri` — pass plaintext values; drivers handle encryption.

Read-only drivers (like `ConfigDriver`) throw `RuntimeException` from this method.

### `isConfigured(): bool`

Whether the repository has a full, usable credential set. All three of `getClientId()`, `getClientSecret()`, `getRedirectUri()` must be non-empty.

## Shipped implementations

| Class | Selected by | Writable? | Details |
|---|---|---|---|
| `ArtisanPackUI\Google\Configuration\ConfigDriver` | `driver = 'config'` (default) | ❌ | Reads from Laravel config / .env. See [Drivers/Config](Drivers-Config). |
| `ArtisanPackUI\Google\Configuration\DatabaseDriver` | `driver = 'database'` | ✅ | Stores in `google_configurations`. Client secret encrypted. See [Drivers/Database](Drivers-Database). |
| `ArtisanPackUI\Google\Configuration\CmsSettingsDriver` | `driver = 'cms'` | ✅ | Delegates to CMS Settings module. Client secret encrypted. See [Drivers/CMS](Drivers-CMS). |

Selecting between them:

```env
GOOGLE_CONFIG_DRIVER=database
```

The service provider binds the contract to the matching driver at container resolution time — `config('google.driver')` is re-read every time the binding is resolved, so runtime overrides work.

## Custom drivers

Implement the interface and rebind:

```php
use ArtisanPackUI\Google\Contracts\ConfigurationRepository;

class VaultDriver implements ConfigurationRepository
{
    public function __construct( protected VaultClient $vault ) {}

    public function getClientId(): ?string { return $this->vault->read( 'google/client_id' ); }
    public function getClientSecret(): ?string { return $this->vault->read( 'google/client_secret' ); }
    public function getRedirectUri(): ?string { return $this->vault->read( 'google/redirect_uri' ); }

    public function save( array $credentials ): void
    {
        foreach ( $credentials as $key => $value ) {
            $this->vault->write( "google/{$key}", $value );
        }
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() && $this->getClientSecret() && $this->getRedirectUri();
    }
}

// AppServiceProvider::register()
$this->app->bind( ConfigurationRepository::class, VaultDriver::class );
```

Since the package uses `bind()` (not `singleton()`), your override wins as long as your provider registers after the package provider — the default order for app providers.
