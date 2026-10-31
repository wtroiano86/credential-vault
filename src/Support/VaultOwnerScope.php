<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Support;

use Illuminate\Database\Eloquent\Model;
use Skillcraft\CredentialVault\Exceptions\InvalidVaultOwnerException;
use Skillcraft\CredentialVault\Models\VaultEntry;

/** @internal */
final class VaultOwnerScope
{
    public static function matchesOwner(VaultEntry $entry, VaultEntry $identity): bool
    {
        return $entry->getOwnerType() === $identity->getOwnerType()
            && $entry->getOwnerId() === $identity->getOwnerId();
    }

    public static function matchesCredential(VaultEntry $entry, VaultEntry $identity): bool
    {
        return self::matchesOwner($entry, $identity)
            && $entry->getCredentialKey() === $identity->getCredentialKey();
    }

    public static function entry(Model $owner, string $key = ''): VaultEntry
    {
        $ownerId = $owner->getKey();
        $model = VaultConfig::model();
        $entry = new $model;

        if (! $owner->exists || (! is_string($ownerId) && ! is_int($ownerId)) || (string) $ownerId === '') {
            throw new InvalidVaultOwnerException('Vault owners must be persisted and have a non-empty integer or string primary key.');
        }

        if ($owner->getConnection() !== $entry->getConnection()) {
            throw new InvalidVaultOwnerException('Vault owners must use the same database connection as the vault entry model.');
        }

        $entry->fill(['owner_type' => $owner->getMorphClass(), 'owner_id' => (string) $ownerId, 'key_name' => $key]);

        return $entry;
    }
}
