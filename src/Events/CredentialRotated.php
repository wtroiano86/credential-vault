<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Events;

/**
 * Fired after a credential has been rotated (re-encrypted with a new value).
 *
 * Does not carry the old or new credential value to prevent sensitive-data
 * leakage in listeners, logs, or event buses.
 */
final readonly class CredentialRotated
{
    /**
     * @param  string  $ownerType  Morph class name of the owner model.
     * @param  string  $ownerId  Primary key value of the owner model.
     * @param  string  $key  Credential key name that was rotated.
     */
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $key,
    ) {}
}
