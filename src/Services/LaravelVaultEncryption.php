<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Services;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Throwable;

/**
 * Laravel-backed implementation of VaultEncryptionInterface.
 *
 * Wraps the application's configured Encrypter for default usage, and derives
 * a per-tenant Encrypter via HKDF when `per_tenant_key` is enabled.  The
 * tenant key is the owner's primary key value; the actual cryptographic key is
 * derived internally — it is never stored or returned.
 */
final class LaravelVaultEncryption implements VaultEncryptionInterface
{
    public function __construct(
        private readonly EncrypterContract $encrypter,
    ) {}

    /**
     * Encrypt a plaintext credential value.
     *
     * @param  string  $value  Plaintext credential to encrypt.
     * @param  string|null  $tenantKey  Owner primary-key value used to derive
     *                                  a per-tenant crypto key.  Null uses the
     *                                  application's default encrypter.
     * @return string Encrypted payload.
     *
     * @throws CryptoOperationException On any encryption failure.
     */
    public function encrypt(string $value, ?string $tenantKey = null): string
    {
        try {
            return $this->resolveEncrypter($tenantKey)->encrypt($value);
        } catch (CryptoOperationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CryptoOperationException(
                message: 'Encryption failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Decrypt an encrypted credential payload.
     *
     * @param  string  $encrypted  Payload produced by {@see encrypt()}.
     * @param  string|null  $tenantKey  Must match the value used during encryption.
     * @return string Decrypted plaintext.
     *
     * @throws CryptoOperationException On any decryption failure.
     */
    public function decrypt(string $encrypted, ?string $tenantKey = null): string
    {
        try {
            $decrypted = $this->resolveEncrypter($tenantKey)->decrypt($encrypted);

            return is_string($decrypted) ? $decrypted : (string) $decrypted;
        } catch (CryptoOperationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CryptoOperationException(
                message: 'Decryption failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Resolve the correct Encrypter instance for the given tenant key.
     *
     * When `$tenantKey` is null the application's default encrypter is returned
     * unchanged.  Otherwise a per-tenant key is derived via HKDF (SHA-256)
     * from the application key and the tenant identifier, then a fresh
     * Encrypter is constructed with that key and the application's cipher.
     *
     * @param  string|null  $tenantKey  Owner primary-key value.
     *
     * @throws CryptoOperationException When the application key cannot be read.
     */
    private function resolveEncrypter(?string $tenantKey): EncrypterContract
    {
        if ($tenantKey === null) {
            return $this->encrypter;
        }

        $appKey = (string) config('app.key', '');

        if ($appKey === '') {
            throw new CryptoOperationException('Application key is not configured.');
        }

        // Strip the "base64:" prefix that Laravel prepends to generated keys.
        $rawKey = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), strict: true)
            : $appKey;

        if ($rawKey === false) {
            throw new CryptoOperationException('Application key is not valid base64.');
        }

        // Derive key length from the configured cipher so the Encrypter
        // constructor receives a key of the correct size.
        $cipher = strtoupper((string) config('app.cipher', 'AES-256-CBC'));
        $keyLength = str_starts_with($cipher, 'AES-128') ? 16 : 32;

        $derivedKey = hash_hkdf(
            algo: 'sha256',
            key: $rawKey,
            length: $keyLength,
            info: 'skillcraft-credential-vault',
            salt: $tenantKey,
        );

        return new Encrypter($derivedKey, $cipher);
    }
}
