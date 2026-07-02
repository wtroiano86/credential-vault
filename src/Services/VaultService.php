<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Exceptions\CredentialNotFoundException;
use Skillcraft\CredentialVault\Exceptions\InvalidCredentialKeyException;
use Skillcraft\CredentialVault\Models\VaultEntry;

/**
 * Core vault service implementation.
 *
 * Handles storing, retrieving, rotating, and deleting encrypted credentials.
 * All credential values are encrypted via {@see VaultEncryptionInterface} before
 * persistence and are never exposed in events, logs, or metadata payloads.
 */
final class VaultService implements VaultServiceInterface
{
    /**
     * Config key prefix for this package.
     */
    private const CONFIG_PREFIX = 'packages.credential-vault.general';

    public function __construct(
        private readonly VaultEncryptionInterface $encryption,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Store a credential for an owner and key.
     *
     * Creates or replaces the entry for the given `$owner` / `$key` pair.
     * Fires {@see CredentialStored} after a successful write.
     *
     * @param  Model  $owner  Owning Eloquent model.
     * @param  string  $key  Credential key name.
     * @param  string  $value  Plaintext credential value.
     * @param  array<string, mixed>  $metadata  Optional metadata (never stores raw value).
     *
     * @throws InvalidCredentialKeyException When the key does not match allowed patterns.
     */
    public function store(Model $owner, string $key, string $value, array $metadata = []): VaultEntryInterface
    {
        $this->validateKey($key);

        [$ownerType, $ownerId] = $this->ownerPair($owner);
        $encrypted = $this->encryption->encrypt($value, $this->getTenantKey($owner));

        /** @var VaultEntry $entry */
        $entry = VaultEntry::updateOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'key_name' => $key],
            ['encrypted_value' => $encrypted, 'metadata' => $metadata],
        );

        $this->events->dispatch(new CredentialStored($ownerType, $ownerId, $key));

        return $entry;
    }

    /**
     * Retrieve a decrypted credential value.
     *
     * Returns `null` when the entry does not exist.  If the entry is expired
     * and `expired.return_null` is enabled in config, `null` is also returned
     * without decrypting.  Fires {@see CredentialAccessed} when audit is on.
     *
     * @param  Model  $owner  Owning Eloquent model.
     * @param  string  $key  Credential key name.
     * @return string|null Decrypted value, or null when absent / expired.
     */
    public function retrieve(Model $owner, string $key): ?string
    {
        [$ownerType, $ownerId] = $this->ownerPair($owner);

        /** @var VaultEntry|null $entry */
        $entry = VaultEntry::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('key_name', $key)
            ->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->isExpired() && $this->config('expired.return_null', true)) {
            return null;
        }

        $encryptedValue = (string) $entry->getAttribute('encrypted_value');
        $value = $this->encryption->decrypt($encryptedValue, $this->getTenantKey($owner));

        if ($this->auditEnabled()) {
            $this->events->dispatch(new CredentialAccessed($ownerType, $ownerId, $key));
        }

        return $value;
    }

    /**
     * Soft-delete a credential entry for an owner and key.
     *
     * Fires {@see CredentialDeleted} after the entry is removed.
     *
     * @param  Model  $owner  Owning Eloquent model.
     * @param  string  $key  Credential key name.
     */
    public function delete(Model $owner, string $key): void
    {
        [$ownerType, $ownerId] = $this->ownerPair($owner);

        VaultEntry::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('key_name', $key)
            ->delete();

        $this->events->dispatch(new CredentialDeleted($ownerType, $ownerId, $key));
    }

    /**
     * Rotate an existing credential with a new value.
     *
     * Re-encrypts the entry and stamps `rotated_at`.  Fires {@see CredentialRotated}.
     *
     * @param  Model  $owner  Owning Eloquent model.
     * @param  string  $key  Credential key name.
     * @param  string  $newValue  New plaintext credential value.
     *
     * @throws InvalidCredentialKeyException When the key does not match allowed patterns.
     * @throws CredentialNotFoundException When no entry exists for the given owner / key.
     */
    public function rotate(Model $owner, string $key, string $newValue): VaultEntryInterface
    {
        $this->validateKey($key);

        [$ownerType, $ownerId] = $this->ownerPair($owner);

        /** @var VaultEntry|null $entry */
        $entry = VaultEntry::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('key_name', $key)
            ->first();

        if ($entry === null) {
            throw new CredentialNotFoundException(
                "Credential '{$key}' not found for this owner."
            );
        }

        $entry->setAttribute('encrypted_value', $this->encryption->encrypt($newValue, $this->getTenantKey($owner)));
        $entry->setAttribute('rotated_at', now());
        $entry->save();

        $this->events->dispatch(new CredentialRotated($ownerType, $ownerId, $key));

        return $entry;
    }

    /**
     * Determine whether a credential entry exists for an owner and key.
     *
     * @param  Model  $owner  Owning Eloquent model.
     * @param  string  $key  Credential key name.
     */
    public function exists(Model $owner, string $key): bool
    {
        [$ownerType, $ownerId] = $this->ownerPair($owner);

        return VaultEntry::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('key_name', $key)
            ->exists();
    }

    /**
     * List stored key metadata for an owner.
     *
     * Returns metadata for every non-deleted entry; never includes raw credential
     * values or encrypted payloads.
     *
     * @param  Model  $owner  Owning Eloquent model.
     * @return array<int, array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string}>
     */
    public function listKeys(Model $owner): array
    {
        [$ownerType, $ownerId] = $this->ownerPair($owner);

        return VaultEntry::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->get()
            ->map(static function (VaultEntry $entry): array {
                return [
                    'key' => $entry->getCredentialKey(),
                    'metadata' => $entry->getMetadata(),
                    'created_at' => $entry->created_at?->toIso8601String() ?? '',
                    'rotated_at' => $entry->getRotatedAt()?->format(DATE_ATOM),
                ];
            })
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that the key name matches at least one allowed pattern.
     *
     * @throws InvalidCredentialKeyException
     */
    private function validateKey(string $key): void
    {
        /** @var list<string> $patterns */
        $patterns = $this->config('allowed_key_patterns', []);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return;
            }
        }

        throw new InvalidCredentialKeyException(
            "Invalid credential key format: '{$key}'."
        );
    }

    /**
     * Return the per-tenant key string when enabled, or null for shared encryption.
     *
     * The owner's primary key value is used as the HKDF salt in
     * {@see LaravelVaultEncryption}; it is not used as a cryptographic key directly.
     */
    private function getTenantKey(Model $owner): ?string
    {
        if (! $this->config('encryption.per_tenant_key', false)) {
            return null;
        }

        return (string) $owner->getKey();
    }

    /**
     * Determine whether audit event dispatching is enabled.
     */
    private function auditEnabled(): bool
    {
        return (bool) $this->config('audit.enabled', false);
    }

    /**
     * Read a value from the package config.
     */
    private function config(string $key, mixed $default = null): mixed
    {
        return config(self::CONFIG_PREFIX.'.'.$key, $default);
    }

    /**
     * Return the [ownerType, ownerId] pair for polymorphic queries.
     *
     * @return array{string, string}
     */
    private function ownerPair(Model $owner): array
    {
        return [$owner->getMorphClass(), (string) $owner->getKey()];
    }
}
