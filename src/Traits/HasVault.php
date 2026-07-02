<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Models\VaultEntry;

/**
 * Adds credential-vault convenience methods to any Eloquent model.
 *
 * Provides a `vaultEntries()` relationship and a set of shorthand methods that
 * delegate to the bound {@see VaultServiceInterface} without requiring callers
 * to resolve the service themselves.
 *
 * Usage:
 * ```php
 * class Tenant extends Model
 * {
 *     use HasVault;
 * }
 *
 * $tenant->storeCredential('stripe_key', 'sk_live_...');
 * $value = $tenant->retrieveCredential('stripe_key');
 * ```
 */
trait HasVault
{
    /**
     * All vault entries owned by this model.
     *
     * @return MorphMany<VaultEntry, $this>
     */
    public function vaultEntries(): MorphMany
    {
        return $this->morphMany(VaultEntry::class, 'owner');
    }

    /**
     * Store a credential in the vault.
     *
     * @param  string  $key  Credential key name.
     * @param  string  $value  Plaintext credential value.
     * @param  array<string, mixed>  $metadata  Optional metadata.
     */
    public function storeCredential(string $key, string $value, array $metadata = []): VaultEntryInterface
    {
        return $this->vaultService()->store($this, $key, $value, $metadata);
    }

    /**
     * Retrieve a decrypted credential value from the vault.
     *
     * @param  string  $key  Credential key name.
     * @return string|null Decrypted value, or null if absent or expired.
     */
    public function retrieveCredential(string $key): ?string
    {
        return $this->vaultService()->retrieve($this, $key);
    }

    /**
     * Delete a credential from the vault (soft-delete).
     *
     * @param  string  $key  Credential key name.
     */
    public function deleteCredential(string $key): void
    {
        $this->vaultService()->delete($this, $key);
    }

    /**
     * Rotate a credential with a new value.
     *
     * @param  string  $key  Credential key name.
     * @param  string  $newValue  New plaintext credential value.
     */
    public function rotateCredential(string $key, string $newValue): VaultEntryInterface
    {
        return $this->vaultService()->rotate($this, $key, $newValue);
    }

    /**
     * Determine whether a credential key exists in the vault.
     *
     * @param  string  $key  Credential key name.
     */
    public function hasCredential(string $key): bool
    {
        return $this->vaultService()->exists($this, $key);
    }

    /**
     * List all stored credential key metadata for this owner.
     *
     * Raw credential values are never included in the returned array.
     *
     * @return array<int, array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string}>
     */
    public function listCredentialKeys(): array
    {
        return $this->vaultService()->listKeys($this);
    }

    /**
     * Resolve the VaultServiceInterface from the container.
     *
     * Kept private so trait users are never forced to call it directly.
     */
    private function vaultService(): VaultServiceInterface
    {
        /** @var VaultServiceInterface */
        return resolve(VaultServiceInterface::class);
    }
}
