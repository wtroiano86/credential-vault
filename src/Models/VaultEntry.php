<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Models;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Override;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Exceptions\InvalidVaultOwnerException;

/**
 * Eloquent model representing an encrypted credential entry in the vault.
 *
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @uses \Illuminate\Database\Eloquent\Factories\HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>>
 */
class VaultEntry extends Model implements VaultEntryInterface
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    /**
     * Get the table associated with the model.
     */
    #[Override]
    public function getTable(): string
    {
        $table = config('packages.credential-vault.general.table', 'scp_vault_entries');

        return is_string($table) ? $table : 'scp_vault_entries';
    }

    /**
     * Get the attributes that are mass assignable.
     *
     * @return list<string>
     */
    #[Override]
    public function getFillable(): array
    {
        return [
            'owner_type',
            'owner_id',
            'key_name',
            'encrypted_value',
            'metadata',
            'rotated_at',
            'expires_at',
        ];
    }

    /**
     * Get the attributes hidden for serialization.
     *
     * @return list<string>
     */
    #[Override]
    public function getHidden(): array
    {
        return [
            'encrypted_value',
        ];
    }

    /**
     * Define attribute casts for this model.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'rotated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the owning model for this vault entry.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the credential key name stored in this entry.
     */
    public function getCredentialKey(): string
    {
        $key = $this->getAttribute('key_name');

        return is_string($key) ? $key : '';
    }

    public function getOwnerType(): string
    {
        $type = $this->getAttribute('owner_type');

        if (! is_string($type) || $type === '') {
            throw new InvalidVaultOwnerException('Vault owner type must be a non-empty string.');
        }

        return $type;
    }

    public function getOwnerId(): string
    {
        $id = $this->getAttribute('owner_id');

        if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
            throw new InvalidVaultOwnerException('Vault owner ID must be a non-empty integer or string.');
        }

        return (string) $id;
    }

    /**
     * Get the metadata payload attached to the entry.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $metadata = $this->getAttribute('metadata');

        if (! is_array($metadata)) {
            return [];
        }

        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Get the date and time the entry was rotated.
     */
    public function getRotatedAt(): ?DateTimeInterface
    {
        $value = $this->getAttribute('rotated_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    /**
     * Get the expiration timestamp for this entry.
     */
    public function getExpiresAt(): ?DateTimeInterface
    {
        $value = $this->getAttribute('expires_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    /**
     * Determine whether this entry has reached its expiration time.
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();

        return $expiresAt instanceof DateTimeInterface && $expiresAt->getTimestamp() <= time();
    }
}
