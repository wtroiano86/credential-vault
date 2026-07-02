<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Events;

/**
 * Fired when a credential has been read from the vault.
 *
 * This event is only dispatched when audit logging is enabled
 * (see `packages.credential-vault.general.audit.enabled`).
 * Does not carry the decrypted value to prevent sensitive-data leakage.
 */
final readonly class CredentialAccessed
{
    /**
     * @param  string  $ownerType  Morph class name of the owner model.
     * @param  string  $ownerId  Primary key value of the owner model.
     * @param  string  $key  Credential key name that was accessed.
     */
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $key,
    ) {}
}
