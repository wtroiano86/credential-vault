<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Events;

/**
 * Fired after a credential has been stored (created or updated) in the vault.
 *
 * Does not carry the raw credential value to prevent sensitive-data leakage
 * in listeners, logs, or event buses.
 */
final readonly class CredentialStored
{
    /**
     * @param  string  $ownerType  Morph class name of the owner model.
     * @param  string  $ownerId  Primary key value of the owner model.
     * @param  string  $key  Credential key name that was stored.
     */
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $key,
    ) {}
}
