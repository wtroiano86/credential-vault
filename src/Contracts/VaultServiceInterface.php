<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Contracts;

use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Defines core lifecycle operations for credential vault management.
 */
interface VaultServiceInterface
{
    /**
     * Store a credential for an owner and key.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function store(Model $owner, string $key, #[SensitiveParameter] string $value, array $metadata = []): VaultEntryInterface;

    /**
     * Retrieve a decrypted credential value.
     */
    public function retrieve(Model $owner, string $key): ?string;

    /**
     * Delete a credential for an owner and key.
     */
    public function delete(Model $owner, string $key): void;

    /**
     * Rotate an existing credential with a new value.
     */
    public function rotate(Model $owner, string $key, #[SensitiveParameter] string $newValue): VaultEntryInterface;

    /**
     * Check whether a credential exists for an owner and key.
     */
    public function exists(Model $owner, string $key): bool;

    /**
     * List stored key metadata for an owner.
     *
     * @return array<int, array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string}>
     */
    public function listKeys(Model $owner): array;
}
