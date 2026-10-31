<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Services;

use SensitiveParameter;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Skillcraft\CredentialVault\Support\VaultConfig;

/**
 * Authenticated credential context, independent of the encryption driver.
 *
 * @internal
 */
final readonly class CredentialCiphertext
{
    private const string PREFIX = 'scv3:';

    private const string MAGIC = "skillcraft-credential-vault:v3\n";

    private const string PREVIOUS_PREFIX = 'scv2:';

    private const string PREVIOUS_MAGIC = "skillcraft-credential-vault:v2\n";

    public function __construct(private VaultEncryptionInterface $encryption) {}

    public function encrypt(#[SensitiveParameter] string $value, VaultEntry $entry): string
    {
        $context = $this->context($entry);

        // Fixed-length hash and marker make the remaining bytes an arbitrary string.
        return self::PREFIX.$this->encryption->encrypt(
            self::MAGIC.$context.$value,
            $this->tenantKey($context),
        );
    }

    public function decrypt(VaultEntry $entry): string
    {
        $encrypted = $this->encryptedValue($entry);

        if (! str_starts_with($encrypted, self::PREFIX)) {
            if (VaultConfig::get('encryption.allow_legacy', false) !== true) {
                throw new CryptoOperationException('Legacy ciphertext requires credential-vault:upgrade-encryption.');
            }

            return $this->decryptLegacy($encrypted, $entry);
        }

        return $this->decryptBound($encrypted, self::PREFIX, self::MAGIC, $this->context($entry));
    }

    /** Return a replacement for legacy ciphertext; verify already upgraded rows. */
    public function upgrade(VaultEntry $entry): ?string
    {
        $encrypted = $this->encryptedValue($entry);

        if (str_starts_with($encrypted, self::PREFIX)) {
            $this->decrypt($entry);

            return null;
        }

        return $this->encrypt($this->decryptLegacy($encrypted, $entry), $entry);
    }

    private function decryptLegacy(string $encrypted, VaultEntry $entry): string
    {
        if (str_starts_with($encrypted, self::PREVIOUS_PREFIX)) {
            return $this->decryptBound($encrypted, self::PREVIOUS_PREFIX, self::PREVIOUS_MAGIC, $this->context($entry, bindTablePrefix: false));
        }

        $value = $this->encryption->decrypt($encrypted, $this->tenantKey($entry->getOwnerId()));

        // Do not let removing the public version marker bypass context verification.
        if (str_starts_with($value, self::MAGIC) || str_starts_with($value, self::PREVIOUS_MAGIC)) {
            throw new CryptoOperationException('Credential ciphertext version marker is missing.');
        }

        return $value;
    }

    private function decryptBound(string $encrypted, string $prefix, string $magic, string $context): string
    {
        $value = $this->encryption->decrypt(substr($encrypted, strlen($prefix)), $this->tenantKey($context));
        $header = $magic.$context;

        if (! hash_equals($header, substr($value, 0, strlen($header)))) {
            throw new CryptoOperationException('Credential ciphertext context does not match.');
        }

        return substr($value, strlen($header));
    }

    private function encryptedValue(VaultEntry $entry): string
    {
        $encrypted = $entry->getAttribute('encrypted_value');

        if (! is_string($encrypted)) {
            throw new CryptoOperationException('Credential ciphertext must be a string.');
        }

        return $encrypted;
    }

    private function context(VaultEntry $entry, bool $bindTablePrefix = true): string
    {
        $context = [
            $entry->getConnection()->getName(),
            $entry->getConnection()->getDatabaseName(),
            $entry->getTable(),
            $entry->getAttribute('owner_type'),
            $entry->getOwnerId(),
            $entry->getAttribute('key_name'),
        ];

        if ($bindTablePrefix) {
            $context[] = $entry->getConnection()->getTablePrefix();
        }

        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function tenantKey(string $context): ?string
    {
        return VaultConfig::get('encryption.per_tenant_key', false) ? $context : null;
    }
}
