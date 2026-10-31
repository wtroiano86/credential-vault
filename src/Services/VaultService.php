<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Exceptions\ConfigurationException;
use Skillcraft\CredentialVault\Exceptions\CredentialNotFoundException;
use Skillcraft\CredentialVault\Exceptions\CredentialPersistenceException;
use Skillcraft\CredentialVault\Exceptions\InvalidCredentialKeyException;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Skillcraft\CredentialVault\Support\VaultConfig;
use Skillcraft\CredentialVault\Support\VaultOwnerScope;

/** Encrypted credential storage scoped to persisted owners on the vault connection. */
final readonly class VaultService implements VaultServiceInterface
{
    private CredentialCiphertext $ciphertext;

    public function __construct(VaultEncryptionInterface $encryption, private Dispatcher $events)
    {
        $this->ciphertext = new CredentialCiphertext($encryption);
    }

    /** @param array<string, mixed> $metadata */
    public function store(Model $owner, string $key, #[SensitiveParameter] string $value, array $metadata = []): VaultEntryInterface
    {
        $this->validateKey($key);
        $identity = $this->identity($owner, $key);
        $encrypted = $this->ciphertext->encrypt($value, $identity);
        $connection = $identity->getConnection();

        // A competing insert may win after the lookup. Retry in a new transaction
        // (or savepoint) so PostgreSQL's failed transaction state is cleared.
        for ($attempt = 0; ; $attempt++) {
            $writeFinished = false;

            try {
                return $connection->transaction(function () use ($identity, $encrypted, $metadata, &$writeFinished): VaultEntry {
                    $entry = $this->query($identity)->withTrashed()->lockForUpdate()->first();

                    // A permissive collation can match a different owner or key,
                    // including a deleted row. Never update or restore that row.
                    if ($entry !== null && ! VaultOwnerScope::matchesCredential($entry, $identity)) {
                        throw new CredentialPersistenceException('Credential storage conflicts with a different identity under the database collation.');
                    }

                    $entry ??= $identity->newInstance($identity->getAttributes());
                    $entry->setAttribute('encrypted_value', $encrypted);
                    $entry->setAttribute('metadata', $metadata);

                    if ($entry->trashed()) {
                        $entry->setAttribute('rotated_at', null);
                        $entry->setAttribute('expires_at', null);
                        $saved = $entry->restore();
                    } else {
                        $saved = $entry->save();
                    }

                    if (! $saved) {
                        throw new CredentialPersistenceException('Credential storage was cancelled by a model observer.');
                    }

                    $this->afterCommit($identity, new CredentialStored(
                        $identity->getOwnerType(), $identity->getOwnerId(), $identity->getCredentialKey(),
                    ));

                    $writeFinished = true;

                    return $entry;
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 1 || $writeFinished) {
                    throw $exception;
                }
            }
        }
    }

    public function retrieve(Model $owner, string $key): ?string
    {
        $identity = $this->identity($owner, $key);
        $entry = $this->query($identity)->first();

        if ($entry === null || ! VaultOwnerScope::matchesCredential($entry, $identity)
            || ($entry->isExpired() && VaultConfig::get('expired.return_null', true))) {
            return null;
        }

        $value = $this->ciphertext->decrypt($entry);

        if (VaultConfig::get('audit.enabled', false)) {
            $this->events->dispatch(new CredentialAccessed(
                $identity->getOwnerType(), $identity->getOwnerId(), $key,
            ));
        }

        return $value;
    }

    public function delete(Model $owner, string $key): void
    {
        $identity = $this->identity($owner, $key);
        $identity->getConnection()->transaction(function () use ($identity): void {
            $entry = $this->query($identity)->lockForUpdate()->first();

            if ($entry === null || ! VaultOwnerScope::matchesCredential($entry, $identity)) {
                return;
            }

            if (! $entry->delete()) {
                throw new CredentialPersistenceException('Credential deletion was cancelled by a model observer.');
            }

            $this->afterCommit($identity, new CredentialDeleted(
                $identity->getOwnerType(), $identity->getOwnerId(), $identity->getCredentialKey(),
            ));
        }, 3);
    }

    public function rotate(Model $owner, string $key, #[SensitiveParameter] string $newValue): VaultEntryInterface
    {
        $this->validateKey($key);
        $identity = $this->identity($owner, $key);
        $encrypted = $this->ciphertext->encrypt($newValue, $identity);

        return $identity->getConnection()->transaction(function () use ($identity, $encrypted): VaultEntry {
            $entry = $this->query($identity)->lockForUpdate()->first();

            if ($entry === null || ! VaultOwnerScope::matchesCredential($entry, $identity)) {
                throw new CredentialNotFoundException('Credential not found for this owner.');
            }

            $entry->setAttribute('encrypted_value', $encrypted);
            $entry->setAttribute('rotated_at', now());

            if (! $entry->save()) {
                throw new CredentialPersistenceException('Credential rotation was cancelled by a model observer.');
            }

            $this->afterCommit($identity, new CredentialRotated(
                $identity->getOwnerType(), $identity->getOwnerId(), $identity->getCredentialKey(),
            ));

            return $entry;
        }, 3);
    }

    public function exists(Model $owner, string $key): bool
    {
        $identity = $this->identity($owner, $key);
        $entry = $this->query($identity)->withoutEagerLoads()->first(['owner_type', 'owner_id', 'key_name']);

        return $entry !== null && VaultOwnerScope::matchesCredential($entry, $identity);
    }

    /** @return array<int, array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string}> */
    public function listKeys(Model $owner): array
    {
        $identity = $this->identity($owner);

        return $identity->newQuery()
            ->where('owner_type', $identity->getAttribute('owner_type'))
            ->where('owner_id', $identity->getAttribute('owner_id'))
            ->get()
            ->filter(static fn (VaultEntry $entry): bool => VaultOwnerScope::matchesOwner($entry, $identity))
            ->map(static fn (VaultEntry $entry): array => [
                'key' => $entry->getCredentialKey(),
                'metadata' => $entry->getMetadata(),
                'created_at' => $entry->created_at?->toIso8601String() ?? '',
                'rotated_at' => $entry->getRotatedAt()?->format(DATE_ATOM),
            ])->values()->all();
    }

    private function validateKey(string $key): void
    {
        $patterns = VaultConfig::get('allowed_key_patterns', []);

        if (! is_array($patterns)) {
            throw new ConfigurationException('Allowed credential key patterns must be an array.');
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || @preg_match($pattern, '') === false) {
                throw new ConfigurationException('Invalid credential key pattern.');
            }

            if (preg_match($pattern, $key) === 1) {
                return;
            }
        }

        throw new InvalidCredentialKeyException('Invalid credential key format.');
    }

    private function identity(Model $owner, string $key = ''): VaultEntry
    {
        return VaultOwnerScope::entry($owner, $key);
    }

    /** @return Builder<VaultEntry> */
    private function query(VaultEntry $identity): Builder
    {
        return $identity->newQuery()
            ->where('owner_type', $identity->getAttribute('owner_type'))
            ->where('owner_id', $identity->getAttribute('owner_id'))
            ->where('key_name', $identity->getCredentialKey());
    }

    private function afterCommit(VaultEntry $identity, object $event): void
    {
        $identity->getConnection()->afterCommit(fn () => $this->events->dispatch($event));
    }
}
