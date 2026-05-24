<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\DTOs;

use JsonSerializable;

/**
 * Immutable data transfer object for vault entry metadata responses.
 */
final readonly class VaultEntryData implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $key,
        public array $metadata,
        public string $createdAt,
        public ?string $rotatedAt = null,
        public ?string $expiresAt = null,
    ) {}

    /**
     * @return array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string, expires_at: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
            'rotated_at' => $this->rotatedAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
