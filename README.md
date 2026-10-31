# skillcraft/credential-vault

Encrypted credential storage for Laravel applications. Credentials are stored in `scp_vault_entries` by default, encrypted at rest, and scoped to a polymorphic owner (workspace, team, user, etc.), with rotation metadata, optional key derivation per credential context, and access auditing via events.

**Requirements:** PHP 8.4+ (8.x) and Laravel / Illuminate 13.x.

Further detail: [docs/credential-vault.md](docs/credential-vault.md).

## Security boundary

The core PHP service, facade, and trait trust application callers to authorize access to the supplied owner. The optional AI tools enforce an explicit key allowlist and Laravel Gate checks, and return only credential names/status. These tool restrictions apply to calls through those tools; they do not restrict an agent that can execute arbitrary PHP or access both vault ciphertext and application encryption keys. Boost skills provide coding guidance, not runtime access controls. See the [security model](docs/credential-vault.md#security-boundary) and [agent integration boundary](docs/agent-tools.md#security-boundary).

## Install

```bash
composer require skillcraft/credential-vault
```

The service provider and `Vault` facade alias are registered via Laravel package discovery ([`composer.json` `extra.laravel`](composer.json)).

## Configuration

Publish the default config (optional; defaults are merged from the package):

```bash
php artisan vendor:publish --tag=skillcraft-credential-vault-config
```

The file is published to `config/packages.credential-vault.general.php`. Values are read as `config('packages.credential-vault.general.*')`. Set `table` before running migrations if you need a different table name. See the [configuration reference](docs/credential-vault.md#configuration) for defaults and legacy config precedence.

The existing `credential-vault-config` publish tag is also supported.

## Database

Migrations ship with the package and are **loaded automatically** when the provider boots. Run:

```bash
php artisan migrate
```

To copy migration files into your app (for customization), publish:

```bash
php artisan vendor:publish --tag=skillcraft-credential-vault-migrations
```

The existing `credential-vault-migrations` publish tag is also supported.

## Upgrading existing installations

Version 2.0 writes `scv3:` ciphertext, which also authenticates the connection's table prefix. When upgrading from 1.x, all existing `scv2:` and older ciphertext must be upgraded, including installations without table prefixes; normal reads reject those formats by default. Pause credential traffic, preserve the original keys, encryption settings, and tenant table prefixes, then validate and migrate:

```bash
php artisan credential-vault:upgrade-encryption --dry-run
php artisan credential-vault:upgrade-encryption
```

Read the [upgrade procedure and compatibility changes](docs/credential-vault.md#security-and-upgrading-existing-installations) before deployment. Run the command for every tenant database/prefix and scope. It includes deleted and expired entries and can be safely rerun. New installations need no ciphertext migration.

## Usage

### `HasVault` on the owner model

```php
use Illuminate\Database\Eloquent\Model;
use Skillcraft\CredentialVault\Traits\HasVault;

class Workspace extends Model
{
    use HasVault;
}
```

Convenience methods (delegate to `VaultServiceInterface`):

| Method | Description |
|--------|-------------|
| `storeCredential(string $key, string $value, array $metadata = []): VaultEntryInterface` | Create, update, or restore a credential and replace its metadata |
| `retrieveCredential(string $key): ?string` | Decrypted value, or `null` if missing or expired (when configured) |
| `deleteCredential(string $key): void` | Soft-delete the entry |
| `rotateCredential(string $key, string $newValue): VaultEntryInterface` | Replace value; throws if missing |
| `hasCredential(string $key): bool` | Whether a non-deleted entry exists, including expired entries |
| `listCredentialKeys(): array` | Key metadata for non-deleted entries, including expired entries |
| `vaultEntries(): MorphMany` | Relationship to `VaultEntry` models |

### `VaultOwner` base model

Alternatively, extend `Skillcraft\CredentialVault\Models\VaultOwner`, an abstract Eloquent model that already includes `HasVault`:

```php
use Skillcraft\CredentialVault\Models\VaultOwner;

class Workspace extends VaultOwner {}
```

All six service operations require a persisted owner with a non-empty integer or string primary key on the same database connection as the configured vault entry model. Invalid owners raise `InvalidVaultOwnerException`. The `vaultEntries()` relationship is a direct Eloquent API and does not apply these service checks. By default, credential names must be 3–100 characters, start with a lowercase letter, end with a lowercase letter or digit, and contain only lowercase letters, digits, and underscores. `store()` and `rotate()` validate these names.

Authorize the actor's access to the owner in your application before calling the vault. Owner scoping does not perform authorization. Keep secret values out of key names, metadata, logs, and API responses; metadata is unencrypted and included in key listings.

Owner types, IDs, and credential names must match exactly, even when the database uses case-insensitive or trailing-space-insensitive comparisons. A conflicting `store()` throws `CredentialPersistenceException`, including when the conflicting row is soft-deleted. Use binary comparison semantics for the vault identity columns and their unique index if such distinct identities must coexist. See the [service behavior](docs/credential-vault.md#services) for other collision outcomes.

Expiry must be set on the entry's `expires_at` column. Updating a non-deleted entry with `store()` or `rotate()` preserves expiry, even if it has already expired. Storing a deleted key restores its existing row with new ciphertext and metadata and clears its old expiry and rotation time. See [service behavior](docs/credential-vault.md#services) for details.

Rotation stores a replacement secret supplied by the caller; it does not generate or revoke credentials at a provider. Deletion is a soft delete and retains ciphertext. Provider validation, revocation, permanent purging, and durable audit logging are application responsibilities.

### `Vault` facade

```php
use Skillcraft\CredentialVault\Facades\Vault;

Vault::store($owner, $key, $value, $metadata);
Vault::retrieve($owner, $key);
Vault::delete($owner, $key);
Vault::rotate($owner, $key, $newValue);
Vault::exists($owner, $key);
Vault::listKeys($owner);
```

### Dependency injection

Bindings: `VaultServiceInterface`, `VaultEncryptionInterface`. Prefer injecting `VaultServiceInterface` in services and jobs instead of the facade.

## Production AI agents

Optional Laravel AI SDK tools are included for credential discovery and status:

- `ListCredentialKeys` lists authorized credential names with bounded pagination.
- `GetCredentialStatus` reports presence, expiry, and last rotation time.

Install `laravel/ai:^1.3` and explicitly add the tools to your agent. Each call requires an application-selected owner, a key allowlist, and a Laravel Gate check. The tools never select ciphertext or metadata, decrypt values, or mutate credentials. The SDK is optional for ordinary vault use.

See the [agent tool setup and production guide](docs/agent-tools.md) for authorization, registration, queued jobs, and response schemas.

## Laravel Boost

The package ships [core](resources/boost/guidelines/core.blade.php), [advanced](resources/boost/guidelines/advanced.blade.php), and [troubleshooting](resources/boost/guidelines/troubleshooting.blade.php) guidelines in `resources/boost/guidelines/`, and three skills in `resources/boost/skills/`:

| Skill | Use it for |
|---|---|
| [credential-vault-development](resources/boost/skills/credential-vault-development/SKILL.md) | Integrating owner models, storing and rotating credentials, expiry, configuration, and events. |
| [credential-vault-encryption-upgrades](resources/boost/skills/credential-vault-encryption-upgrades/SKILL.md) | Planning and validating legacy ciphertext upgrades and diagnosing encryption failures. |
| [credential-vault-agent-tools](resources/boost/skills/credential-vault-agent-tools/SKILL.md) | Adding authorized credential discovery/status tools to Laravel AI SDK agents. |

In a consuming Laravel application, install Boost and select this package and the skills feature during setup:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

After updating this package, refresh the installed resources with `php artisan boost:update`. If skills were not selected previously, rerun `boost:install` to enable them. Boost discovers the package's skill folders using its [third-party package skill convention](https://laravel.com/docs/13.x/boost#third-party-package-skills); no vault publish command or runtime Boost dependency is needed.

These skills guide coding agents during development. The optional [Laravel AI tools](docs/agent-tools.md) run inside your application and require separate SDK installation and registration.

## Development checks

From the package repository:

```bash
composer install
composer test:compact
composer lint:check
composer analyse
composer audit --locked
```

`composer test:unit` and `composer test:integration` run the suites separately. `composer test:coverage` requires a supported coverage driver; with Xdebug, use `XDEBUG_MODE=coverage composer test:coverage`. `XDEBUG_MODE=coverage composer check` runs formatting checks, static analysis, and the coverage suite. Integration tests use SQLite and fake AI provider responses; they do not validate a deployment's database configuration or live provider credentials. Keep the README, reference docs, Boost guidelines, and skills aligned when public behavior changes.
