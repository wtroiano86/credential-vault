<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Contracts;

use DateTimeInterface;

/**
 * Describes a stored vault entry.
 */
interface VaultEntryInterface
{
    /**
     * Get the credential key name stored in this entry.
     */
    public function getCredentialKey(): string;

    /**
     * Get entry metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Get the timestamp when the entry was last rotated.
     */
    public function getRotatedAt(): ?DateTimeInterface;

    /**
     * Get the credential expiration timestamp.
     */
    public function getExpiresAt(): ?DateTimeInterface;

    /**
     * Determine whether the credential is expired.
     */
    public function isExpired(): bool;
}
