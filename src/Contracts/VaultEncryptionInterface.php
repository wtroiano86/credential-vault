<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Contracts;

/**
 * Defines encryption and decryption operations for vault values.
 */
interface VaultEncryptionInterface
{
    /**
     * Encrypt a plaintext credential value.
     */
    public function encrypt(string $value, ?string $tenantKey = null): string;

    /**
     * Decrypt an encrypted credential value.
     */
    public function decrypt(string $encrypted, ?string $tenantKey = null): string;
}
