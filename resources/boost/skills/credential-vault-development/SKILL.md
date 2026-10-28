---
name: credential-vault-development
description: Integrate skillcraft/credential-vault into Laravel applications using HasVault, VaultOwner, or VaultServiceInterface. Use for credential storage, retrieval, rotation, expiry, owner scoping, custom implementations, and lifecycle events.
---

# Credential Vault development

Use the package's service contract for credential values. The package supports PHP 8.4+ (8.x) and Laravel / Illuminate 13.x.

## Locate the integration

Inspect the application's owner model, authorization policy, vault configuration, and existing service usage. In a consuming app, the package reference is `vendor/skillcraft/credential-vault/docs/credential-vault.md`; in the package repository, use `docs/credential-vault.md`. Consult it for custom drivers, configuration defaults, and exception contracts.

- Add `Skillcraft\CredentialVault\Traits\HasVault` to the existing owner model, or extend `Models\VaultOwner` when it fits the app's model hierarchy. The facade and service also accept models without the trait.
- Every operation requires a persisted owner with a non-empty integer or string ID on the configured vault entry model's connection. Unsaved or cross-connection owners throw `InvalidVaultOwnerException`.
- Authorize access to the selected owner in application code. The service scopes rows but does not authorize the actor.
- Prefer injecting `Contracts\VaultServiceInterface` into services/jobs. `Facades\Vault` exposes the same six operations.

## Install and configure

```bash
composer require skillcraft/credential-vault
php artisan vendor:publish --tag=skillcraft-credential-vault-config
php artisan migrate
```

Config publishing is optional. The published file is `config/packages.credential-vault.general.php`, accessed through `config('packages.credential-vault.general.{key}')`. Migrations load automatically; set `table` before migrating if changing the default `scp_vault_entries`.

Existing `packages.skillcraft-credential-vault.general.{key}` overrides take precedence for service settings. Remove stale overrides when migrating config. Nested config groups merge shallowly, so retain other desired keys when replacing a group. Models/migrations read only the current namespace for `table`.

## Implement the lifecycle

```php
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;

// $workspace is persisted and already authorized by the application.
// $submittedSecret comes from validated application input, never a chat prompt.
$vault = app(VaultServiceInterface::class);
$entry = $vault->store($workspace, 'stripe_secret', $submittedSecret, [
    'provider' => 'stripe',
]);

$secret = $vault->retrieve($workspace, 'stripe_secret');
if ($secret === null) {
    // Ask the application user to configure or renew the credential.
    return;
}
// Use $secret only in the server-side integration that needs it.
```

| Operation | Behavior to preserve |
|---|---|
| `store($owner, $key, $value, $metadata = [])` | Creates or updates; replaces metadata, including with `[]` when omitted. Restores a deleted row with new ciphertext/metadata and clears expiry/rotation time. Active updates preserve both timestamps. |
| `retrieve($owner, $key)` | Returns plaintext or `null` for missing/deleted entries, and expired entries by default. Invalid ciphertext throws `CryptoOperationException`. |
| `rotate($owner, $key, $newValue)` | Requires a non-deleted entry; records `rotated_at`, preserving metadata and expiry. Missing/deleted keys throw `CredentialNotFoundException`. |
| `delete($owner, $key)` | Soft-deletes; a missing key is a no-op. |
| `exists($owner, $key)` | Checks existence without decryption or expiry enforcement. |
| `listKeys($owner)` | Returns `key`, `metadata`, `created_at`, `rotated_at` arrays for non-deleted entries, including expired ones. No pagination, ordering guarantee, or `expires_at` field. |

The trait equivalents are `storeCredential`, `retrieveCredential`, `rotateCredential`, `deleteCredential`, `hasCredential`, and `listCredentialKeys`.

Only store/rotate validate credential names. The default pattern `/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/` accepts 3–100 characters, starts with a lowercase letter, ends with a lowercase letter or digit, and allows lowercase letters, digits, or underscores between. `key` is valid; `ok` and `OpenAI-Key` are invalid.

## Expiry, customization, and events

- Set `expires_at` on the entry model explicitly; metadata does not control expiry. Rotation of an expired entry succeeds but does not renew expiry. `expired.return_null = false` permits decrypting expired entries.
- `store()` returns `VaultEntryInterface`, which has no Eloquent write methods. Narrow to `Models\VaultEntry` for model updates, or use the owner relationship when it targets the correct model. `HasVault::vaultEntries()` always uses the base entry class; override it if the configured custom subclass is needed.
- Select custom classes in `services.vault`, `services.encryption`, or `model`. The service/encryption classes are `final readonly`; implement their contracts or compose them. A custom entry model must extend `VaultEntry`.
- Mutation events carry only `ownerType`, `ownerId`, and `key`, and dispatch after the outermost transaction commits. Rollbacks, observer vetoes, and missing deletes emit no mutation event. Observer vetoes throw `CredentialPersistenceException`.
- `CredentialAccessed` fires immediately after successful decryption only when `audit.enabled` is true. `audit.log_channel` is reserved; logging requires an application listener. Events do not provide durable audit delivery.

Keep secrets out of metadata, key names, logs, and response DTOs. Metadata is unencrypted and unsanitized. Do not write secret values directly through Eloquent, or copy ciphertext to a new owner/key/table: ciphertext authenticates that identity. Add `#[\SensitiveParameter]` to application methods that accept plaintext secrets.

For an existing-data format upgrade or a change to encryption settings, use `credential-vault-encryption-upgrades`. For model-facing inspection, use `credential-vault-agent-tools`; exposing the service's metadata listings or plaintext retrieval to an agent is not the bundled tool contract.

## Verify the changed behavior

Use persisted owners and the real configured vault connection in integration tests. Exercise the lifecycle branch being changed, owner isolation, and application authorization. For expiry tests, use timestamps clearly in the past/future: `VaultEntry::isExpired()` compares against PHP's `time()`. For mutation events, commit the outer transaction before asserting dispatch. Avoid logging plaintext or database bindings when diagnosing failures.
