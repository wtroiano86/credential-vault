# skillcraft/credential-vault — Encrypted Credential Storage

> Composer: `skillcraft/credential-vault`  
> Namespace: `Skillcraft\CredentialVault`  
> Depends on: `skillcraft/support` (configuration loading, `LoadAndPublishDataTrait`)

## Purpose

Provides per-tenant encrypted storage for third-party API keys and secrets. Credentials are encrypted at rest, accessible by key name, rotatable, and auditable via domain events. Suitable for any multi-tenant Laravel application that needs secure credential management (payments, SaaS integrations, webhooks, etc.).

## Core Concepts

- **VaultEntry** — Eloquent model for one encrypted credential: polymorphic `owner`, `key_name`, `encrypted_value`, optional `metadata`, `rotated_at`, `expires_at`, soft deletes.
- **VaultService** — Default implementation of `VaultServiceInterface`: store, retrieve, delete, rotate, exists, list keys; validates key names; dispatches lifecycle events.
- **LaravelVaultEncryption** — Default `VaultEncryptionInterface` using Laravel encryption, with optional HKDF-based per-owner derivation.
- **HasVault** — Trait on the owning model for ergonomic access without resolving the service manually.

## API Surface

### Contracts

```php
<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Contracts;

use Illuminate\Database\Eloquent\Model;

interface VaultServiceInterface
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function store(Model $owner, string $key, string $value, array $metadata = []): VaultEntryInterface;

    public function retrieve(Model $owner, string $key): ?string;

    public function delete(Model $owner, string $key): void;

    public function rotate(Model $owner, string $key, string $newValue): VaultEntryInterface;

    public function exists(Model $owner, string $key): bool;

    /**
     * @return array<int, array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string}>
     */
    public function listKeys(Model $owner): array;
}
```

```php
<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Contracts;

interface VaultEncryptionInterface
{
    public function encrypt(string $value, ?string $tenantKey = null): string;

    public function decrypt(string $encrypted, ?string $tenantKey = null): string;
}
```

```php
<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Contracts;

use DateTimeInterface;

interface VaultEntryInterface
{
    public function getCredentialKey(): string;

    /** @return array<string, mixed> */
    public function getMetadata(): array;

    public function getRotatedAt(): ?DateTimeInterface;

    public function getExpiresAt(): ?DateTimeInterface;

    public function isExpired(): bool;
}
```

### Traits

`Skillcraft\CredentialVault\Traits\HasVault` adds:

- `vaultEntries(): MorphMany` — related `VaultEntry` rows.
- `storeCredential($key, $value, $metadata = [])` → `VaultServiceInterface::store()`
- `retrieveCredential($key)` → `retrieve()`
- `deleteCredential($key)` → `delete()`
- `rotateCredential($key, $newValue)` → `rotate()`
- `hasCredential($key)` → `exists()`
- `listCredentialKeys()` → `listKeys()`

### Services

- **`VaultService`** — Validates keys against `allowed_key_patterns`, encrypts via `VaultEncryptionInterface`, respects `expired.return_null` on retrieve, dispatches events (see below). **Does not** populate `expires_at` from `store()` metadata; set that column on the `VaultEntry` (or extend the service) if you use expiry.
- **`LaravelVaultEncryption`** — Uses Laravel’s encrypter; optional `encryption.per_tenant_key` passes the owner’s primary key into HKDF-style derivation (see implementation).

### Facades

- **`Vault`** (`Skillcraft\CredentialVault\Facades\Vault`) — Facade for `VaultServiceInterface`: `store`, `retrieve`, `delete`, `rotate`, `exists`, `listKeys`.

### Data Objects

`Skillcraft\CredentialVault\DTOs\VaultEntryData` is a readonly `JsonSerializable` DTO for **metadata-only** responses (`key`, `metadata`, `created_at`, `rotated_at`, `expires_at`). It never includes plaintext or ciphertext.

## Eloquent Integration

### Model: `VaultEntry`

Table: `vault_entries` (overridable via config `table` / `model` if you extend the package).

| Column | Type | Notes |
|--------|------|-------|
| `id` | ULID | Primary key |
| `owner_type` | string | Polymorphic owner class |
| `owner_id` | string | Owner key (string for ULID owners) |
| `key_name` | string | Credential identifier (e.g. `openai_api_key`) |
| `encrypted_value` | text | Ciphertext (hidden from serialization) |
| `metadata` | json (nullable) | Non-secret context |
| `rotated_at` | timestamp (nullable) | Set on `rotate()` |
| `expires_at` | timestamp (nullable) | Used by `VaultEntry::isExpired()` / retrieve behavior |
| `created_at`, `updated_at` | timestamps | |
| `deleted_at` | timestamp (nullable) | Soft deletes |

Unique index on `(owner_type, owner_id, key_name)`. Additional indexes may be added by package migrations (e.g. `expires_at`).

### Migrations (package)

- `2026_02_21_000000_create_vault_entries_table.php` — creates `vault_entries` with unique constraint and soft deletes.
- `2026_02_21_000001_add_performance_indexes_to_vault_entries_table.php` — performance indexes.

## Configuration

**Merged file:** `config/general.php` in the package; when published, merged under:

`config('packages.credential-vault.general.{key}')`

Shape (see repository `config/general.php` for the live default):

```php
<?php

declare(strict_types=1);

return [
    'services' => [
        'vault' => \Skillcraft\CredentialVault\Services\VaultService::class,
        'encryption' => \Skillcraft\CredentialVault\Services\LaravelVaultEncryption::class,
    ],

    'table' => 'scp_vault_entries',
    'model' => \Skillcraft\CredentialVault\Models\VaultEntry::class,

    'encryption' => [
        'per_tenant_key' => false,
    ],

    'expired' => [
        'return_null' => true,
    ],

    'audit' => [
        'enabled' => true,
        'log_channel' => null,
    ],

    'allowed_key_patterns' => [
        '/^[a-z][a-z0-9_]{1,98}[a-z0-9]$/',
    ],
];
```

**Audit:** When `audit.enabled` is true, `CredentialAccessed` is dispatched from `retrieve()`. The `log_channel` key is reserved for future use; it is not read by the default `VaultService` (use event listeners for logging).

## Events

| Event | Payload | Dispatched when |
|-------|---------|-----------------|
| `CredentialStored` | `ownerType`, `ownerId`, `key` | After successful store / upsert |
| `CredentialRotated` | `ownerType`, `ownerId`, `key` | After successful rotate |
| `CredentialAccessed` | `ownerType`, `ownerId`, `key` | After successful decrypt on retrieve, if `audit.enabled` |
| `CredentialDeleted` | `ownerType`, `ownerId`, `key` | After soft-delete |

Payloads never include the secret value.

## Exceptions

- **`InvalidCredentialKeyException`** — Key does not match any `allowed_key_patterns` (e.g. on `store` / `rotate`).
- **`CredentialNotFoundException`** — `rotate()` when no entry exists for owner + key.
- **`CryptoOperationException`** — Encryption/decryption failure in `LaravelVaultEncryption`.
- **`ConfigurationException`** — Defined for configuration errors; not thrown by core package code in the current release (available for custom bindings / app code).

`retrieve()` returns `null` for missing keys (and for expired entries when `expired.return_null` is true); it does not throw `CredentialNotFoundException`.

## Service Provider

**Class:** `Skillcraft\CredentialVault\Providers\CredentialVaultServiceProvider`

- Uses `LoadAndPublishDataTrait` with namespace `packages/credential-vault`.
- **`register()`:** Sets package base path, merges/publishes `general` config, registers singletons for `VaultEncryptionInterface` and `VaultServiceInterface` (class names from config, with package defaults).
- **`boot()`:** `loadMigrations(publish: true)` so migrations run from the package and can be published with tag `skillcraft-credential-vault-migrations`.

## Dependencies

**Requires:** `skillcraft/support` — `LoadAndPublishDataTrait` for config and migration publishing paths.

**Optional consumers:** Any app or package that owns credentials (e.g. workspace packages) can use `HasVault` on their owner models and listen to the events above for compliance or integration logging.
