<?php

/**
 * CMS Framework Settings driver for app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage Google
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Google\Configuration;

use ArtisanPackUI\Google\Contracts\ConfigurationRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores Google OAuth app credentials in the CMS framework's Settings module.
 *
 * Only registered when `artisanpack-ui/cms-framework` is installed. Delegates
 * reads and writes to `apGetSetting()` / `apUpdateSetting()` (with a matching
 * `apRegisterSetting()` at boot time) so credentials live alongside every other
 * site-level setting the CMS manages. The client secret is encrypted using the
 * framework Encrypter before being written to the Settings table.
 *
 * @since 1.0.0
 */
class CmsSettingsDriver implements ConfigurationRepository
{
    public const KEY_CLIENT_ID     = 'artisanpack_google_client_id';

    public const KEY_CLIENT_SECRET = 'artisanpack_google_client_secret';

    public const KEY_REDIRECT_URI  = 'artisanpack_google_redirect_uri';

    /**
     * @var array<string, string|null>|null
     */
    protected ?array $cache = null;

    public function __construct( protected Encrypter $encrypter )
    {
    }

    public function getClientId(): ?string
    {
        return $this->load()[ 'client_id' ] ?? null;
    }

    public function getClientSecret(): ?string
    {
        return $this->load()[ 'client_secret' ] ?? null;
    }

    public function getRedirectUri(): ?string
    {
        return $this->load()[ 'redirect_uri' ] ?? null;
    }

    public function save( array $credentials ): void
    {
        // Pass plaintext through. Encryption for the client_secret key is
        // owned by the sanitize callback that GoogleServiceProvider registers
        // with the CMS framework, so both this write path and any operator
        // saving through the Settings UI persist the same ciphertext shape.
        apUpdateSetting( self::KEY_CLIENT_ID, $credentials[ 'client_id' ] ?? null );
        apUpdateSetting( self::KEY_CLIENT_SECRET, $credentials[ 'client_secret' ] ?? null );
        apUpdateSetting( self::KEY_REDIRECT_URI, $credentials[ 'redirect_uri' ] ?? null );

        $this->cache = null;
    }

    public function isConfigured(): bool
    {
        return ! empty( $this->getClientId() )
            && ! empty( $this->getClientSecret() )
            && ! empty( $this->getRedirectUri() );
    }

    /**
     * Clear the per-request cache; primarily for tests.
     *
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, string|null>
     */
    protected function load(): array
    {
        if ( null !== $this->cache ) {
            return $this->cache;
        }

        $clientId     = apGetSetting( self::KEY_CLIENT_ID );
        $secretCipher = apGetSetting( self::KEY_CLIENT_SECRET );
        $redirectUri  = apGetSetting( self::KEY_REDIRECT_URI );

        $secret = null;
        if ( ! empty( $secretCipher ) && is_string( $secretCipher ) ) {
            try {
                $secret = $this->encrypter->decryptString( $secretCipher );
            } catch ( Throwable $e ) {
                Log::warning(
                    'artisanpack-ui/google: failed to decrypt CMS-stored client_secret; treating as unconfigured. Was APP_KEY rotated without re-encrypting the setting?',
                    [ 'exception' => $e::class, 'message' => $e->getMessage() ],
                );
                $secret = null;
            }
        }

        return $this->cache = [
            'client_id'     => null === $clientId ? null : (string) $clientId,
            'client_secret' => $secret,
            'redirect_uri'  => null === $redirectUri ? null : (string) $redirectUri,
        ];
    }
}
