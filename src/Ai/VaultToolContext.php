<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Ai;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Skillcraft\CredentialVault\Support\VaultOwnerScope;

/** An application-supplied owner, Gate, and key allowlist for a single agent run. */
final readonly class VaultToolContext
{
    public const string ABILITY = 'viewVaultCredential';

    public const int MAX_KEYS = 100;

    public const int MAX_PAGE_SIZE = 50;

    /** @var list<string> */
    private array $keys;

    /** @param array<array-key, mixed> $keys Credential names the application permits this agent to inspect. */
    public function __construct(private Model $owner, private Gate $gate, array $keys)
    {
        if (! array_is_list($keys) || count($keys) > self::MAX_KEYS) {
            throw new InvalidArgumentException('Vault tool keys must be a list of at most 100 names.');
        }

        $validated = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Vault tool credential names must be strings.');
            }
            $this->validateKey($key);
            $validated[] = $key;
        }
        $this->keys = array_values(array_unique($validated));
    }

    /** @return array{keys: list<string>, next_cursor: ?string, has_more: bool} */
    public function listKeys(int $limit = 25, ?string $after = null): array
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidToolArgumentsException('Vault tool page size must be between 1 and 50.');
        }

        if ($after !== null) {
            $this->validateKey($after);
        }

        $query = $this->query();
        $allowed = array_values(array_filter($this->keys, fn (string $key): bool => $this->gate->allows(self::ABILITY, [$this->owner, $key])));

        if ($allowed === []) {
            return ['keys' => [], 'next_cursor' => null, 'has_more' => false];
        }

        $identity = $query->getModel();
        // Database collations may compare distinct names/IDs as equal. Apply
        // exact owner/key checks before returning anything to the agent.
        $keys = $query->whereIn('key_name', $allowed)->limit(self::MAX_KEYS)
            ->get(['owner_type', 'owner_id', 'key_name'])
            ->filter(fn (VaultEntry $entry): bool => VaultOwnerScope::matchesOwner($entry, $identity)
                && in_array($entry->getCredentialKey(), $allowed, true))
            ->map(static fn (VaultEntry $entry): string => $entry->getCredentialKey())->all();
        sort($keys, SORT_STRING);

        if ($after !== null) {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => strcmp($key, $after) > 0));
        }

        $hasMore = count($keys) > $limit;
        $keys = array_slice($keys, 0, $limit);
        $lastKey = array_key_last($keys);

        return ['keys' => $keys, 'next_cursor' => $hasMore && $lastKey !== null ? $keys[$lastKey] : null, 'has_more' => $hasMore];
    }

    /**
     * @return array{key: string, stored: bool, expired: ?bool, expires_at: ?string, rotated_at: ?string}|array{error: string}
     */
    public function status(string $key): array
    {
        $this->validateKey($key);
        $query = $this->query();

        if (! in_array($key, $this->keys, true) || ! $this->gate->allows(self::ABILITY, [$this->owner, $key])) {
            return ['error' => 'not_authorized'];
        }

        $identity = $query->getModel();
        $entry = $query->where('key_name', $key)->first(['owner_type', 'owner_id', 'key_name', 'expires_at', 'rotated_at']);

        if ($entry !== null && (! VaultOwnerScope::matchesOwner($entry, $identity) || $entry->getCredentialKey() !== $key)) {
            $entry = null;
        }

        return [
            'key' => $key,
            'stored' => $entry !== null,
            'expired' => $entry?->isExpired(),
            'expires_at' => $entry?->getExpiresAt()?->format(DATE_ATOM),
            'rotated_at' => $entry?->getRotatedAt()?->format(DATE_ATOM),
        ];
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 255 || preg_match('/\A[^\x00-\x1F\x7F]+\z/u', $key) !== 1) {
            throw new InvalidToolArgumentsException('Vault tool credential names must be valid UTF-8, at most 255 bytes, without control characters.');
        }
    }

    /** @return Builder<VaultEntry> */
    private function query(): Builder
    {
        $entry = VaultOwnerScope::entry($this->owner);

        return $entry->newQueryWithoutRelationships()
            ->where('owner_type', $entry->getOwnerType())
            ->where('owner_id', $entry->getOwnerId());
    }
}
