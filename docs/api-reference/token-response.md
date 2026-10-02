---
title: TokenResponse
---

# `TokenResponse`

`ArtisanPackUI\Google\OAuth\TokenResponse` is an immutable value object describing a successful code exchange or token refresh. Added in 1.2.0. Both [`GoogleClient`](API-Reference-Google-Client) and [`BrokerClient`](API-Reference-Broker-Client) return it, and nothing about it is persisted.

## Signature

```php
namespace ArtisanPackUI\Google\OAuth;

final class TokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly string $tokenType,
        public readonly ?int $expiresIn,
        public readonly ?Carbon $expiresAt,
        public readonly array $scopes,        // list<string>
        public readonly ?string $idToken,
        public readonly ?string $accountId,
        public readonly ?string $accountEmail,
        public readonly ?string $accountName,
    );

    public static function fromGoogle( array $payload, ?string $fallbackRefreshToken = null ): self;
    public static function fromBroker( array $payload, ?string $fallbackRefreshToken = null ): self;
    public function toArray(): array;
}
```

## Properties

| Property | Description |
|---|---|
| `accessToken` | Short-lived access token. |
| `refreshToken` | The refresh token from the payload, else `$fallbackRefreshToken`. |
| `tokenType` | `token_type` from the payload, default `Bearer`. |
| `expiresIn` | Lifetime in seconds, or `null` when not reported. |
| `expiresAt` | `Carbon::now()->addSeconds( expiresIn )`, or `null`. |
| `scopes` | Granted scopes, trimmed with empty entries removed. **An empty list means the provider didn't report scopes.** |
| `idToken` | Raw `id_token` JWT. |
| `accountId` | `sub` claim of the `id_token`. |
| `accountEmail` | `account_email` from the payload, else the `email` claim. |
| `accountName` | `account_name` from the payload, else the `name` claim. |

The `id_token` claims are decoded **without signature verification**. Use them for display and labeling, never for authorization.

## Factories

### `fromGoogle( array $payload, ?string $fallbackRefreshToken = null ): self`

Parses a Google token-endpoint payload. `scope` is a space-separated string.

### `fromBroker( array $payload, ?string $fallbackRefreshToken = null ): self`

Parses a broker `/token` or `/refresh` payload. `scopes` is an array, and `account_email` / `account_name` are read directly.

Both expect `access_token` to be present. The clients check this before building.

## `toArray(): array`

Renders the broker's site-facing response shape:

```php
[
    'token_type'    => 'Bearer',
    'access_token'  => '…',
    'refresh_token' => '…',
    'expires_in'    => 3599,
    'scopes'        => [ '…' ],
    'account_email' => '…',
    'account_name'  => '…',
    'id_token'      => '…',
]
```

`TokenResponse::fromBroker( $response->toArray() )` round-trips.

## Related

- [Stateless Client](Stateless-Client)
- [Broker Mode](Broker-Mode)
