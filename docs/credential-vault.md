# skillcraft/credential-vault — Encrypted Credential Storage

> Composer: `skillcraft/credential-vault`  
> Namespace: `Skillcraft\CredentialVault`  
> Requirements: PHP 8.4+ (8.x) and Laravel / Illuminate 13.x

## Purpose

Provides per-tenant encrypted storage for third-party API keys and secrets. Credentials are encrypted at rest, accessible by key name, rotatable, and auditable via domain events. Suitable for any multi-tenant Laravel application that needs secure credential management (payments, SaaS integrations, webhooks, etc.).

## Core Concepts

- **VaultEntry** — Eloquent model for one encrypted credential: polymorphic `owner`, `key_name`, `encrypted_value`, optional `metadata`, `rotated_at`, `expires_at`, soft deletes.
- **VaultService** — Default implementation of `VaultServiceInterface`: store, retrieve, delete, rotate, exists, list keys; validates key names; dispatches lifecycle events.
- **LaravelVaultEncryption** — Default `VaultEncryptionInterface` using Laravel encryption, with optional HKDF-based per-owner derivation.
- **HasVault** — Trait on the owning model for ergonomic access without resolving the service manually.
- **VaultOwner** — Abstract Eloquent base model that includes `HasVault`.

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
    public function store(Model $owner, string $key, #[\SensitiveParameter] string $value, array $metadata = []): VaultEntryInterface;

    public function retrieve(Model $owner, string $key): ?string;

    public function delete(Model $owner, string $key): void;

    public function rotate(Model $owner, string $key, #[\SensitiveParameter] string $newValue): VaultEntryInterface;

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
    public function encrypt(#[\SensitiveParameter] string $value, ?string $tenantKey = null): string;

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

- **`VaultService`** — Scopes queries by the owner's `getMorphClass()` and string primary key, encrypts via `VaultEncryptionInterface`, and dispatches lifecycle events. Every operation requires a persisted owner with a non-empty integer or string key on the same database connection as the vault entry model. Both built-in service classes are `final readonly`; customize behavior by implementing the corresponding contract and selecting it in config.
- **`LaravelVaultEncryption`** — Uses the injected Laravel encrypter by default. The service authenticates the owner type, string owner ID, credential name, connection name, database name, and table inside a versioned encrypted payload. With `encryption.per_tenant_key` enabled, that context's SHA-256 digest is also the HKDF salt; derivation uses the current `app.key`, HKDF-SHA-256, and `skillcraft-credential-vault` as the info value. Encryption uses `app.cipher`. String encryption/decryption disables PHP serialization; the legacy decoder accepts only serialized strings without calling `unserialize()`.

| Method | Default behavior |
|--------|------------------|
| `store()` | Creates, updates, or restores an owner/key entry, replacing ciphertext and metadata. Active updates preserve expiry and rotation time; restoring a deleted row clears both. |
| `retrieve()` | Returns decrypted plaintext, or `null` for missing/deleted entries or expired entries when `expired.return_null` is true. Invalid or mismatched ciphertext throws `CryptoOperationException`. |
| `delete()` | Soft-deletes the matching entry; dispatches `CredentialDeleted` after commit only if an entry was deleted. |
| `rotate()` | Requires a non-deleted entry; replaces ciphertext and stamps `rotated_at`, preserving metadata and expiry. Expired entries can be rotated. |
| `exists()` | Checks for a non-deleted entry without decrypting or checking expiry. |
| `listKeys()` | Returns all non-deleted entries, including expired ones, with `key`, `metadata`, `created_at`, and `rotated_at`. No `expires_at` field or explicit ordering is included. |

Only `store()` and `rotate()` validate credential names against `allowed_key_patterns`. A match against any configured pattern is sufficient; an empty list rejects all stores and rotations. The default pattern is strictly anchored with `\A` and `\z` (including rejection of trailing newlines) and permits 3–100 characters: a lowercase starting letter, a lowercase letter or digit at the end, and lowercase letters, digits, or underscores between them. `key` is valid; `ok`, `1key`, and `OpenAI-Key` are invalid.

Expiry is controlled by the `expires_at` column, not metadata. Set it directly on the entry when needed. An entry is expired when its expiry timestamp is at or before the current time. With `expired.return_null = false`, retrieval decrypts expired entries normally. Rotating or updating an active entry does not renew expiry. Storing a soft-deleted entry restores it and clears its previous expiry and rotation time.

The unique owner/key constraint includes soft-deleted rows. `store()` locks and restores a deleted entry in a transaction. Store, rotate, and delete check model-observer vetoes and throw `CredentialPersistenceException` if cancelled. Mutation events run after the outermost transaction commits on the vault connection; rollback discards them. A synchronous event-listener failure can propagate after the database has committed.

### Facades

- **`Vault`** (`Skillcraft\CredentialVault\Facades\Vault`) — Facade for `VaultServiceInterface`: `store`, `retrieve`, `delete`, `rotate`, `exists`, `listKeys`.

### Data Objects

`Skillcraft\CredentialVault\DTOs\VaultEntryData` is a final readonly `JsonSerializable` DTO for **metadata-only** responses (`key`, `metadata`, `created_at`, `rotated_at`, `expires_at`). Its constructor accepts `key`, `metadata`, `createdAt`, and optional nullable `rotatedAt` and `expiresAt` strings. It has no credential-value field. Metadata is caller-supplied and is not scrubbed for secrets; keep it non-sensitive. `listKeys()` returns arrays directly rather than DTO instances.

## Production AI agent tools

The optional `Ai\Tools\ListCredentialKeys` and `Ai\Tools\GetCredentialStatus` classes implement the Laravel AI SDK's tool contract. They require `laravel/ai:^1.3` in the consuming application and explicit registration in an agent's `tools()` method. `Ai\VaultToolContext` binds a persisted owner, a Gate instance for the actor, and at most 100 allowed key names; the `viewVaultCredential` ability is checked per key on every invocation. No SDK dependency or tool registration is added to ordinary vault usage.

These tools query only identity/status columns on the configured Eloquent entry model. Metadata and ciphertext are excluded, values are never decrypted, and status does not guarantee that a credential works. No mutation or secret-retrieval tools are included. See the [complete agent tool guide](agent-tools.md) for integration examples, output/error schemas, and production responsibilities.

## Eloquent Integration

### Model: `VaultEntry`

Default table: `scp_vault_entries`, configured by `packages.credential-vault.general.table`. Both the model and migrations read this setting; configure it before migrating.

The service's `model` option accepts `VaultEntry` or a subclass, and falls back to `VaultEntry` for invalid values. `HasVault::vaultEntries()` always targets the base `VaultEntry` class; override that relationship in your owner model if it must use your custom entry model.

| Column | Type | Notes |
|--------|------|-------|
| `id` | ULID | Primary key |
| `owner_type` | string | Owner morph class or configured morph-map alias |
| `owner_id` | string | Owner primary key cast to a string |
| `key_name` | string | Credential identifier (e.g. `openai_api_key`) |
| `encrypted_value` | text | Ciphertext (hidden from serialization) |
| `metadata` | json (nullable) | Non-secret context |
| `rotated_at` | timestamp (nullable) | Set on `rotate()` |
| `expires_at` | timestamp (nullable) | Used by `VaultEntry::isExpired()` / retrieve behavior |
| `created_at`, `updated_at` | timestamps | |
| `deleted_at` | timestamp (nullable) | Soft deletes |

Unique index on `(owner_type, owner_id, key_name)`, including soft-deleted rows. The second migration adds indexes on `(owner_type, owner_id)`, `deleted_at`, and `expires_at`.

### Model: `VaultOwner`

`Skillcraft\CredentialVault\Models\VaultOwner` is an abstract Eloquent model using `HasVault`. Extend it for the same relationship and credential convenience methods, or keep your existing model base class and add the trait directly.

### Migrations (package)

- `2026_02_21_000000_create_vault_entries_table.php` — creates the configured table (default `scp_vault_entries`) with a unique owner/key constraint and soft deletes.
- `2026_02_21_000001_add_performance_indexes_to_vault_entries_table.php` — adds the owner, deletion, and expiry indexes to that table.

Migrations load automatically from the package. Run `php artisan migrate`; optionally publish them to `database/migrations` with `php artisan vendor:publish --tag=skillcraft-credential-vault-migrations`.

## Configuration

**Default file:** `config/general.php` in the package. The provider merges defaults during registration, whether or not the file has been published. Publish with `php artisan vendor:publish --tag=skillcraft-credential-vault-config` to create `config/packages.credential-vault.general.php`. Access values under:

`config('packages.credential-vault.general.{key}')`

Application values take precedence over defaults. Laravel's merge is shallow, so a supplied nested group such as `services` or `audit` replaces that default group; keep the other desired keys when editing it.

**Legacy configuration:** The service and provider also read `packages.skillcraft-credential-vault.general.{key}`. An existing legacy value takes precedence over the current namespace for the requested key. Migrate old overrides to the current namespace and remove stale legacy values. The entry model and migrations read only the current `table` setting.

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
        'allow_legacy' => false,
    ],

    'expired' => [
        'return_null' => true,
    ],

    'audit' => [
        'enabled' => true,
        'log_channel' => null,
    ],

    'allowed_key_patterns' => [
        '/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/',
    ],
];
```

**Audit:** When `audit.enabled` is true, `CredentialAccessed` is dispatched after a successful decryption in `retrieve()`. Calls returning `null` or throwing do not dispatch it. The `log_channel` key is reserved; it is not read by the default `VaultService` (use event listeners for logging).

**Encryption changes:** `credential-vault:upgrade-encryption` upgrades the legacy payload format using the existing key, cipher, and encryption mode. It is not a key-rotation or database-relocation command. The default encrypter can use Laravel's configured previous keys; derived mode uses only the current `app.key`. Changing the mode, cipher, key, owner identity, credential name, connection/database name, or table requires decrypting with the original settings and re-storing with the new ones.

## Security and upgrading existing installations

All new service writes use the `scv2:` format with authenticated credential context. The built-in driver uses a separate `scv1:` string encoding internally. Neither layer accepts a removed or mismatched version marker as a valid new payload. Direct database edits that change owner identity or credential name invalidate the ciphertext.

Legacy ciphertext has no authenticated owner/name binding. Reads are disabled by default (`encryption.allow_legacy = false`). Upgrade before resuming normal traffic:

1. Back up the database and original encryption keys. Pause credential reads and writes, including workers running older package code.
2. Keep the existing `app.key`, cipher, encryption mode, morph aliases, and database/table settings. Review the integrity of legacy ownership mappings; an upgrade cannot identify substitution that happened before binding was introduced.
3. Review published config, including legacy overrides. Replace the old `$`-anchored default key pattern with `/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/`. Verify all caller models are persisted and use the configured vault model's connection. Applications with a central vault and owners on other connections must migrate to a supported layout or supply their own service.
4. Run the commands below. A nonzero exit status means some rows failed; those rows are left unchanged. Resolve the failure (for example, a missing original key or a vetoing observer) and rerun. Previously upgraded rows are verified and skipped.
5. Confirm `Failed: 0`, keep `allow_legacy = false`, and resume traffic. Old package versions cannot read the new format; rollback requires a coordinated data/key backup restore.

```bash
php artisan credential-vault:upgrade-encryption --dry-run
php artisan credential-vault:upgrade-encryption
```

The command processes bounded batches, locks and updates each row in its own transaction, includes expired and soft-deleted rows, and preserves metadata, expiry, deletion state, and timestamps. It uses the configured entry model/connection and encryption implementation. Custom global scopes other than soft deletion remain active: ensure the command can see every intended row, and run it for each tenant scope/database your application uses. `Failed: 0` covers only the rows visible to that invocation.

Dry run validates decryption and replacement encryption without persisting; it does not exercise save observers. Counts are printed without plaintext or driver exceptions for per-row failures. Successful partial progress remains committed; the command is resumable. It does not emit credential lifecycle events, although Eloquent save observers run on actual upgrades and can veto or alter a write. Review custom observers before running the migration.

For a staged deployment, explicitly setting `encryption.allow_legacy = true` temporarily permits legacy reads. Those rows remain vulnerable to ciphertext substitution until upgraded; the default rejects them. It never enables PHP object deserialization. Custom encryption implementations must preserve arbitrary string bytes, authenticate ciphertext, support reading their own legacy format, and apply `#[\SensitiveParameter]` to their concrete secret parameters (interface attributes are not inherited).

Package entry points redact secret arguments in exception traces. Also set `zend.exception_ignore_args = On` in production to cover application callers and custom drivers; never put secrets in key names, metadata, or exception messages. Model serialization hides ciphertext, but code with model/database access can still read it. Event dispatch is not a durable audit outbox: process failure after commit can lose an event. Use a transactional outbox in the application if guaranteed audit delivery is required.

The vault does not authorize callers. Check application policies before passing an owner model. A principal with the application key can decrypt credentials; optional derivation does not isolate tenants from application-key compromise. Context authentication prevents substitution across credentials, but does not detect replay of an older valid ciphertext for the same credential or authenticate metadata/expiry columns.

## Events

| Event | Payload | Dispatched when |
|-------|---------|-----------------|
| `CredentialStored` | `ownerType`, `ownerId`, `key` | After store / upsert commits |
| `CredentialRotated` | `ownerType`, `ownerId`, `key` | After rotation commits |
| `CredentialAccessed` | `ownerType`, `ownerId`, `key` | After successful decrypt on retrieve, if `audit.enabled` |
| `CredentialDeleted` | `ownerType`, `ownerId`, `key` | After a matching entry is deleted and the transaction commits |

Payloads never include the secret value. Mutation events wait for the outermost transaction commit and are discarded on rollback; deletion of a missing key emits no event. `CredentialAccessed` is immediate after decryption, even within a transaction. Only `CredentialAccessed` is gated by `audit.enabled`; store, rotation, and deletion events dispatch regardless of that setting.

## Exceptions

- **`InvalidCredentialKeyException`** — `store()` or `rotate()` receives a key that does not match any `allowed_key_patterns`.
- **`CredentialNotFoundException`** — `rotate()` finds no non-deleted entry for owner + key.
- **`CryptoOperationException`** — Encryption/decryption failure, invalid ciphertext or context mismatch, or a legacy payload when compatibility reads are disabled.
- **`InvalidVaultOwnerException`** — An operation receives an unsaved owner, an invalid ID, or an owner on a different database connection.
- **`CredentialPersistenceException`** — A model observer cancels a save, restoration, rotation, or deletion.
- **`ConfigurationException`** — Key pattern configuration is invalid, or the provider resolves a configured service that does not implement `VaultEncryptionInterface` or `VaultServiceInterface`. Missing or unresolvable classes fail during Laravel container resolution instead.

`retrieve()` returns `null` for missing keys (and for expired entries when `expired.return_null` is true); it does not throw `CredentialNotFoundException`.

## Service Provider

**Class:** `Skillcraft\CredentialVault\Providers\CredentialVaultServiceProvider`

- **`register()`:** Uses Laravel's `mergeConfigFrom()` to merge `general` config under `packages.credential-vault.general`, then registers singletons for `VaultEncryptionInterface` and `VaultServiceInterface` (class names from config, with package defaults).
- **`boot()`:** Registers `credential-vault:upgrade-encryption`. Uses Laravel's `loadMigrationsFrom()` so migrations run from the package, and `publishes()` to expose config and migrations with tags `skillcraft-credential-vault-config` and `skillcraft-credential-vault-migrations`. The existing `credential-vault-config` and `credential-vault-migrations` tags remain supported.

## Dependencies

**Requires:** PHP 8.4+ (8.x) and Laravel / Illuminate 13.x (`console`, `contracts`, `database`, `encryption`, `log`, and `support`). Configuration and migration loading and publishing use Laravel's built-in service provider methods.

**Optional AI integration:** `laravel/ai:^1.3` is suggested for the bundled agent tools and installed as a development dependency for tests.

**Optional consumers:** Any app or package that owns credentials (e.g. workspace packages) can use `HasVault` on their owner models and listen to the events above for compliance or integration logging.

## Laravel Boost skills

The package includes `credential-vault-development`, `credential-vault-encryption-upgrades`, and `credential-vault-agent-tools` skills alongside its Blade guidelines. See the [README's Boost setup](../README.md#laravel-boost) for installation, updates, and links to each skill. Boost is optional development tooling; it does not register the production Laravel AI tools or change vault behavior.
