## skillcraft/credential-vault

Encrypted per-tenant credential storage for Laravel. Stores third-party API keys and secrets encrypted at rest, scoped to any polymorphic owner (Workspace, Team, User), with built-in rotation, expiry, and audit event dispatch.

Requires PHP 8.4+ (8.x) and Laravel / Illuminate 13.x. The default table is `scp_vault_entries`; key derivation per credential context is optional and disabled by default.

The core service/facade/trait trust application callers to authorize the actor for the owner/key. The bundled AI tools enforce their own allowlist and Gate checks; these controls do not restrict direct service calls, arbitrary application PHP, or access to ciphertext and application encryption keys. Boost guidance is not a runtime access control. Use trusted, loaded owner models; validation checks Eloquent's `exists` flag and ID/connection without re-querying the owner table.

### Skills

When installed through Boost, use `credential-vault-development` for application integration, `credential-vault-encryption-upgrades` for legacy ciphertext migration or decryption failures, and `credential-vault-agent-tools` for Laravel AI SDK tool integration. Package reference docs are under `vendor/skillcraft/credential-vault/docs/` in consuming applications, or `docs/` in the package repository. Boost skills guide development; they do not register runtime AI tools.

### Installation

@verbatim
<code-snippet name="Install the package" lang="bash">
composer require skillcraft/credential-vault
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Publish configuration" lang="bash">
php artisan vendor:publish --tag=skillcraft-credential-vault-config
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Run migrations" lang="bash">
php artisan migrate
</code-snippet>
@endverbatim

### Configuration

Config is accessed via `config('packages.credential-vault.general.{key}')`. Defaults are merged even without publishing. The published file is `config/packages.credential-vault.general.php`. Existing `credential-vault-config` and `credential-vault-migrations` publish tags remain supported alongside the `skillcraft-credential-vault-*` tags.

The service and provider give existing `packages.skillcraft-credential-vault.general.{key}` legacy values precedence; remove stale legacy overrides when migrating config. The entry model and migrations use only the current namespace for `table`.

@verbatim
<code-snippet name="Key configuration options" lang="php">
// Selected options from config/packages.credential-vault.general.php
return [
    'table' => 'scp_vault_entries',
    'model' => \Skillcraft\CredentialVault\Models\VaultEntry::class,

    'encryption' => [
        'per_tenant_key' => false, // true = HKDF-derived key per credential context
        'allow_legacy' => false,  // temporary compatibility during a controlled upgrade only
    ],

    'audit' => [
        'enabled' => true,         // dispatches CredentialAccessed after successful decryption
        'log_channel' => null,     // reserved; use event listeners to log to a channel
    ],

    'allowed_key_patterns' => [
        '/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/', // 3–100 chars, lowercase starting letter
    ],

    'expired' => [
        'return_null' => true,     // false allows expired credentials to be decrypted
    ],
];
</code-snippet>
@endverbatim

### Features

#### HasVault Trait — Credential Access on Owner Models

Apply `HasVault` to an Eloquent model that owns credentials. The six credential operations delegate to `VaultServiceInterface`; `vaultEntries()` returns a direct relationship to the base `VaultEntry` model. For service calls, use a persisted owner with a non-empty integer or string primary key on the same database connection as the configured vault entry model. Service operations reject invalid owners with `InvalidVaultOwnerException`; the relationship does not apply those checks.

@verbatim
<code-snippet name="Apply HasVault to an owner model" lang="php">
use Illuminate\Database\Eloquent\Model;
use Skillcraft\CredentialVault\Traits\HasVault;

class Workspace extends Model
{
    use HasVault;
}
</code-snippet>
@endverbatim

Alternatively, extend the abstract `Skillcraft\CredentialVault\Models\VaultOwner` model, which already includes `HasVault`.

@verbatim
<code-snippet name="Store, retrieve, and delete a credential via the trait" lang="php">
// Store a credential (encrypts automatically)
$workspace->storeCredential('openai_api_key', 'sk-...', [
    'label' => 'OpenAI Production Key',
    'provider' => 'openai',
]);

// Retrieve the decrypted value
$apiKey = $workspace->retrieveCredential('openai_api_key'); // 'sk-...' or null

// Check existence without decrypting
if ($workspace->hasCredential('stripe_secret')) {
    // ...
}

// Delete (soft-delete)
$workspace->deleteCredential('openai_api_key');
</code-snippet>
@endverbatim

#### Vault Facade — Direct Service Access

Use `Vault` when you need to operate on behalf of an owner without the trait on the model.

@verbatim
<code-snippet name="Store and retrieve credentials via the Vault facade" lang="php">
use Skillcraft\CredentialVault\Facades\Vault;

// Store — returns VaultEntryInterface
$entry = Vault::store($workspace, 'github_token', 'ghp_...', [
    'label' => 'GitHub Actions Token',
]);

// Retrieve decrypted value
$token = Vault::retrieve($workspace, 'github_token');

// Check existence
$exists = Vault::exists($workspace, 'github_token'); // bool

// Delete
Vault::delete($workspace, 'github_token');
</code-snippet>
@endverbatim

#### Credential Rotation

Rotation replaces the encrypted value supplied by the caller, timestamps `rotated_at`, and dispatches `CredentialRotated`. It preserves metadata and `expires_at`, and throws `CredentialNotFoundException` when no exact, non-deleted entry exists. Updating a non-deleted entry with `store()` replaces its metadata but does not stamp `rotated_at` or change expiry, even if already expired. Storing a deleted entry restores it and clears both timestamps. Rotation does not provision or revoke provider credentials or rotate encryption keys. Soft deletion retains ciphertext; expiry does not purge it.

@verbatim
<code-snippet name="Rotate a credential to a new value" lang="php">
// Via trait
$entry = $workspace->rotateCredential('stripe_secret', 'sk_live_newkey...');

// Via Vault facade
$entry = Vault::rotate($workspace, 'stripe_secret', 'sk_live_newkey...');

echo $entry->getRotatedAt()?->format(DATE_ATOM); // timestamp of rotation
</code-snippet>
@endverbatim

#### List Credential Keys

Returns `key`, `metadata`, `created_at`, and `rotated_at` for every non-deleted entry, including expired entries. No `expires_at` field or explicit sort order is included. Credential values are omitted; caller-provided metadata must also be kept free of secrets.

@verbatim
<code-snippet name="List all credential keys for an owner" lang="php">
// Via trait
$keys = $workspace->listCredentialKeys();

// Via Vault facade
$keys = Vault::listKeys($workspace);

// Each entry: ['key' => 'openai_api_key', 'metadata' => [...], 'created_at' => '...', 'rotated_at' => null]
foreach ($keys as $entry) {
    echo $entry['key'];
}
</code-snippet>
@endverbatim

#### Vault Entry Relationship

The morphMany relationship exposes raw `VaultEntry` records for trusted application code. It uses database comparison rules and bypasses service validation, exact identity checks, encryption, expiry filtering, and credential events. Check identity before using results; prefer service methods for credential operations.

@verbatim
<code-snippet name="Query vault entries as Eloquent records" lang="php">
// $workspace is loaded and authorized. Find a candidate using database comparisons.
$key = 'openai_api_key';
$entry = $workspace->vaultEntries()
    ->where('key_name', $key)
    ->first();

if ($entry !== null && (
    $entry->getOwnerType() !== $workspace->getMorphClass()
    || $entry->getOwnerId() !== (string) $workspace->getKey()
    || $entry->getCredentialKey() !== $key
)) {
    $entry = null;
}

echo $entry?->getCredentialKey(); // 'openai_api_key' (getKey() returns the ULID)
$expired = $entry?->isExpired();  // bool when found, null when absent
</code-snippet>
@endverbatim

### Credential Key Naming Rules

By default, keys must match `/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/`: 3–100 characters, beginning with a lowercase letter, ending with a lowercase letter or digit, and containing only lowercase letters, digits, and underscores. Underscores are optional. Only `store()` and `rotate()` validate names and throw `InvalidCredentialKeyException`. A key must match at least one configured pattern; an empty pattern list rejects all stores and rotations.

@verbatim
<code-snippet name="Valid and invalid key names" lang="php">
// Valid
$workspace->storeCredential('openai_api_key', $value);
$workspace->storeCredential('stripe_secret_key', $value);
$workspace->storeCredential('github_token', $value);
$workspace->storeCredential('key', $value);           // three letters is valid

// Invalid — throws InvalidCredentialKeyException
$workspace->storeCredential('OpenAI_Key', $value);  // uppercase
$workspace->storeCredential('my-key', $value);      // hyphens not allowed
$workspace->storeCredential('ok', $value);          // fewer than three characters
$workspace->storeCredential('1key', $value);        // starts with a digit
</code-snippet>
@endverbatim

### Events

All events carry `ownerType`, `ownerId`, and `key` — never the raw credential value or acting user identity. Correlate the actor in application audit code. Mutation events dispatch after the outermost transaction commits and are discarded on rollback. Model vetoes raise `CredentialPersistenceException`. Deleting a missing row emits no event. Access events follow successful decryption immediately. Synchronous listeners can throw after dispatch begins; mutation listener failures happen after the write commits. Events are not a durable audit store.

`CredentialAccessed` fires only after successful decryption when `audit.enabled` is true. `CredentialStored`, `CredentialRotated`, and `CredentialDeleted` are independent of that setting. Deleting a missing entry does not dispatch `CredentialDeleted`.

@verbatim
<code-snippet name="Listen to credential lifecycle events" lang="php">
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;

// In your application service provider's boot() method
Event::listen(CredentialStored::class, function (CredentialStored $event) {
    // $event->ownerType, $event->ownerId, $event->key
    Log::info("Credential stored: {$event->key} for {$event->ownerType}:{$event->ownerId}");
});

Event::listen(CredentialRotated::class, function (CredentialRotated $event) {
    // Notify team, update integrations, etc.
});
</code-snippet>
@endverbatim

### Best Practices

- **Do:** Handle `null` from `retrieveCredential()` directly. `hasCredential()` checks existence, including expired entries, so it does not guarantee a value can be retrieved.
- **Do:** Store non-sensitive metadata (provider name, label, scopes); it is stored as unencrypted JSON and returned in listings and DTOs.
- **Do:** Use `rotateCredential()` / `Vault::rotate()` to replace a non-deleted credential and record the latest rotation time. `store()` restores soft-deleted rows with the replacement value and clears their expiry and rotation time.
- **Do:** Set or renew `expires_at` explicitly when needed, preferably on the model returned by storage/rotation. Neither method reads expiry from metadata. Non-deleted rows preserve expiry; storing a deleted entry clears it.
- **Do:** Use snake_case key names that describe the provider + purpose (e.g., `openai_api_key`, `stripe_webhook_secret`).
- **Don't:** Put decrypted values in logs, session, cache, or DTO metadata; metadata is not scrubbed for secrets.
- **Don't:** Read `encrypted_value` directly from the model — always go through `Vault::retrieve()` or `retrieveCredential()`.
- **Don't:** Write credential values directly through `VaultEntry`; use the service or trait for encryption and events. Direct model updates are needed to set expiry.

### Security Upgrade

New `scv3:` writes authenticate the owner type/ID, credential name, and connection/database/table/prefix context. Reads of `scv2:` and older legacy formats are disabled by default. Installations with older ciphertext must upgrade, even without table prefixes; new empty installations need no ciphertext upgrade. Pause credential traffic, back up data and keys, keep the original key/cipher/mode and tenant prefixes, and run `php artisan credential-vault:upgrade-encryption --dry-run` followed by `php artisan credential-vault:upgrade-encryption` for every tenant scope/database/prefix. The command includes expired/deleted rows and is resumable; resolve all failures before resuming traffic. Keep `encryption.allow_legacy = false`; temporary compatibility reads retain the older formats' substitution risks. Update published regex defaults to use `\A` and `\z`. See `docs/credential-vault.md` for the full upgrade procedure.

Service operations require exact owner type/ID and key matches, independently of database collation. A colliding store/restoration throws `CredentialPersistenceException`; other lookups treat mismatched identities as missing. Configure binary identity-column/index comparisons when distinct case or trailing-space identities must coexist. Direct Eloquent relationships do not gain these service checks.

Authorize owner access in the application. Keep secrets out of metadata, key names, and logs. Use `zend.exception_ignore_args = On` in production; package secret parameters are redacted, but custom callers/drivers also need protection. Context authentication does not prevent replay of old ciphertext for the same credential.

### Production Laravel AI Agents

With the optional `laravel/ai:^1.3` dependency, register `Ai\Tools\ListCredentialKeys` and `Ai\Tools\GetCredentialStatus` from an agent's `tools()` method. Construct `Ai\VaultToolContext` with a persisted owner, `Gate::forUser($actor)`, and an explicit allowlist of at most 100 input names. Configure `viewVaultCredential` with `[$owner, $key]` arguments; both the allowlist and the Gate must permit inspection. Normal Gate policies and `before` hooks apply. Every call rechecks access. Never derive actor/owner identity from model-generated tool arguments.

Tools return JSON strings with fixed fields. List pages contain at most 50 keys and use `next_cursor`/`after`. Status includes `stored`, `expired`, `expires_at`, and `rotated_at`. No metadata or ciphertext is selected, and no secret is decrypted or mutated. `stored` does not verify provider validity. There is no automatic tool registration or MCP endpoint. See `docs/agent-tools.md` for the full contract and production setup.
