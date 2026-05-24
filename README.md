# skillcraft/credential-vault

Encrypted per-tenant credential storage for Laravel applications. Credentials are stored in `vault_entries`, encrypted at rest, scoped to a polymorphic owner (workspace, team, user, etc.), with rotation metadata and optional access auditing via events.

**Requirements:** PHP 8.4 or 8.5, Laravel / Illuminate 13.x, and [`skillcraft/support`](https://github.com/skillcraft-io/skillcraft-support) (auto-installed as a dependency).

Further detail: [docs/credential-vault.md](docs/credential-vault.md).

## Install

```bash
composer require skillcraft/credential-vault
```

The service provider and `Vault` facade alias are registered via Laravel package discovery ([`composer.json` `extra.laravel`](composer.json)).

## Configuration

Publish the merged config (optional; defaults are merged from the package):

```bash
php artisan vendor:publish --tag=skillcraft-credential-vault-config
```

Values are read as `config('packages.skillcraft-credential-vault.general.*')`.

## Database

Migrations ship with the package and are **loaded automatically** when the provider boots. Run:

```bash
php artisan migrate
```

To copy migration files into your app (for customization), publish:

```bash
php artisan vendor:publish --tag=skillcraft-credential-vault-migrations
```

## Usage

### `HasVault` on the owner model

```php
use Skillcraft\CredentialVault\Traits\HasVault;

class Workspace extends Model
{
    use HasVault;
}
```

Convenience methods (delegate to `VaultServiceInterface`):

| Method | Description |
|--------|-------------|
| `storeCredential(string $key, string $value, array $metadata = []): VaultEntryInterface` | Create or replace a credential |
| `retrieveCredential(string $key): ?string` | Decrypted value, or `null` if missing or expired (when configured) |
| `deleteCredential(string $key): void` | Soft-delete the entry |
| `rotateCredential(string $key, string $newValue): VaultEntryInterface` | Replace value; throws if missing |
| `hasCredential(string $key): bool` | Whether a non-deleted entry exists |
| `listCredentialKeys(): array` | Key metadata only (no secret values) |
| `vaultEntries(): MorphMany` | Relationship to `VaultEntry` models |

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

## Laravel Boost

When this package is installed, Laravel Boost merges guideline snippets from `resources/boost/guidelines/` for AI-assisted development. Keep those blades aligned with this README and [docs/credential-vault.md](docs/credential-vault.md) when the public API changes.
