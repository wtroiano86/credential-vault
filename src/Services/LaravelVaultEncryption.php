<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Services;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Throwable;

/**
 * Laravel-backed implementation of VaultEncryptionInterface.
 *
 * Wraps the application's configured Encrypter for default usage, and derives
 * a per-tenant Encrypter via HKDF when `per_tenant_key` is enabled.  The
 * tenant key is the credential context (the owner ID for legacy ciphertext); the actual cryptographic key is
 * derived internally — it is never stored or returned.
 */
final readonly class LaravelVaultEncryption implements VaultEncryptionInterface
{
    private const string PREFIX = 'scv1:';

    private const string MAGIC = "skillcraft-credential-vault:string:v1\n";

    public function __construct(
        private EncrypterContract $encrypter,
    ) {}

    /**
     * Encrypt a plaintext credential value.
     *
     * @param  string  $value  Plaintext credential to encrypt.
     * @param  string|null  $tenantKey  Credential context used to derive
     *                                  a per-tenant crypto key.  Null uses the
     *                                  application's default encrypter.
     * @return string Encrypted payload.
     *
     * @throws CryptoOperationException On any encryption failure.
     */
    public function encrypt(#[SensitiveParameter] string $value, ?string $tenantKey = null): string
    {
        try {
            return self::PREFIX.$this->resolveEncrypter($tenantKey)->encrypt(self::MAGIC.$value, false);
        } catch (CryptoOperationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CryptoOperationException(message: 'Encryption failed: '.$e->getMessage(), code: $e->getCode(), previous: $e);
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
            $versioned = str_starts_with($encrypted, self::PREFIX);
            $payload = $versioned ? substr($encrypted, strlen(self::PREFIX)) : $encrypted;
            $decrypted = $this->resolveEncrypter($tenantKey)->decrypt($payload, false);

            if (! is_string($decrypted)) {
                throw new CryptoOperationException('Decryption did not return a string value.');
            }

            if ($versioned) {
                if (! str_starts_with($decrypted, self::MAGIC)) {
                    throw new CryptoOperationException('Invalid ciphertext format.');
                }

                return substr($decrypted, strlen(self::MAGIC));
            }

            return $this->decodeLegacyString($decrypted);
        } catch (CryptoOperationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CryptoOperationException(message: 'Decryption failed: '.$e->getMessage(), code: $e->getCode(), previous: $e);
        }
    }

    /** Decode only PHP's serialized string format, without invoking unserialize. */
    private function decodeLegacyString(#[SensitiveParameter] string $serialized): string
    {
        if (preg_match('/\As:(0|[1-9][0-9]*):"(.*)";\z/s', $serialized, $matches) !== 1
            || (string) strlen($matches[2]) !== $matches[1]) {
            throw new CryptoOperationException('Legacy ciphertext does not contain a serialized string.');
        }

        return $matches[2];
    }

    /**
     * Resolve the correct Encrypter instance for the given tenant key.
     *
     * When `$tenantKey` is null the application's default encrypter is returned
     * unchanged.  Otherwise a per-tenant key is derived via HKDF (SHA-256)
     * from the application key and the tenant identifier, then a fresh
     * Encrypter is constructed with that key and the application's cipher.
     *
     * @param  string|null  $tenantKey  Credential context (owner ID for legacy ciphertext).
     *
     * @throws CryptoOperationException When the application key cannot be read.
     */
    private function resolveEncrypter(?string $tenantKey): EncrypterContract
    {
        if ($tenantKey === null) {
            return $this->encrypter;
        }

        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
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
        $configuredCipher = config('app.cipher', 'AES-256-CBC');
        $cipher = strtoupper(is_string($configuredCipher) ? $configuredCipher : 'AES-256-CBC');
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
