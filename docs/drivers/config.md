---
title: Config Driver
---

# `config` Driver

The default driver. Reads credentials from Laravel's config repository, which in turn reads from `.env`.

## Configuration

```env
GOOGLE_CONFIG_DRIVER=config    # optional, this is the default
GOOGLE_CLIENT_ID=1234567890-abcdef.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxxxxxxxxxx
GOOGLE_REDIRECT_URI=https://your-app.test/google/auth/callback
```

The keys map directly to `config/google.php`:

```php
'client_id'     => env( 'GOOGLE_CLIENT_ID' ),
'client_secret' => env( 'GOOGLE_CLIENT_SECRET' ),
'redirect_uri'  => env( 'GOOGLE_REDIRECT_URI' ),
```

You can also set these values directly in the config file if you'd rather commit them (obviously don't commit the client secret).

## Read-only

The `config` driver is read-only. Calling `Google::config()->save([...])` throws a `RuntimeException`:

```
The config driver is read-only. Switch to the database driver to persist credentials.
```

This is deliberate — the config driver's whole point is that credentials live in your deploy pipeline. Writing back to the file wouldn't be persisted across container restarts, and mutating environment values at runtime tends to leak between requests.

Need to update credentials programmatically? Switch to the [`database`](Drivers-Database) or [`cms`](Drivers-CMS) driver.

## When to use it

- Single-tenant apps.
- Credentials rotate rarely enough that a redeploy is fine.
- You already manage secrets through a deploy pipeline (Envoyer, Vapor, GitLab CI variables, Kubernetes secrets, …).

## When not to use it

- Multi-tenant apps where each tenant has their own OAuth client.
- Admin-UI-managed credentials.
- Anywhere users need to see or edit the credentials without a deploy.

## `isConfigured()` behavior

Returns `true` only when all three values are non-empty:

```php
public function isConfigured(): bool
{
    return ! empty( $this->getClientId() )
        && ! empty( $this->getClientSecret() )
        && ! empty( $this->getRedirectUri() );
}
```

Which means the check will flip to `false` the moment a container boots without the env vars set — useful as a "did I forget to sync .env?" tripwire.

## Testing

Point the config repository at fixture values in your test's `setUp()`:

```php
config( [
    'google.driver'        => 'config',
    'google.client_id'     => 'test-client-id',
    'google.client_secret' => 'test-client-secret',
    'google.redirect_uri'  => 'https://tests.test/google/auth/callback',
] );
```

Since Orchestra Testbench doesn't read your app's `.env`, the config driver is often the easiest to test against.
