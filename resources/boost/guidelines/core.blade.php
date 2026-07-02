## skillcraft/credential-vault

Encrypted per-tenant credential storage for Laravel. Stores third-party API keys and secrets encrypted at rest, scoped to any polymorphic owner (Workspace, Team, User), with built-in rotation, expiry, and audit event dispatch.

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

Config is accessed via `config('packages.credential-vault.general.{key}')`.

@verbatim
<code-snippet name="Key configuration options" lang="php">
// config/general.php (published)
return [
    'encryption' => [
        'per_tenant_key' => false, // true = HKDF-derived key per owner
    ],

    'audit' => [
        'enabled' => true,         // dispatches CredentialAccessed events on retrieve
        'log_channel' => null,     // reserved; use event listeners to log to a channel
    ],

    'allowed_key_patterns' => [
        '/^[a-z][a-z0-9_]{1,98}[a-z0-9]$/', // keys must be snake_case, 3–100 chars
    ],

    'expired' => [
        'return_null' => true,     // expired credentials return null instead of throwing
    ],
];
</code-snippet>
@endverbatim

### Features

#### HasVault Trait — Credential Access on Owner Models

Apply `HasVault` to any Eloquent model that owns credentials. All methods delegate to `VaultServiceInterface` automatically.

@verbatim
<code-snippet name="Apply HasVault to an owner model" lang="php">
use Skillcraft\CredentialVault\Traits\HasVault;

class Workspace extends Model
{
    use HasVault;
}
</code-snippet>
@endverbatim

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

Rotation atomically replaces the encrypted value and timestamps `rotated_at`. Dispatches `CredentialRotated`.

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

Returns key metadata only — the actual secret values are never included in the listing.

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

Access raw `VaultEntry` Eloquent records directly via the morphMany relationship.

@verbatim
<code-snippet name="Query vault entries as Eloquent records" lang="php">
// All entries for this owner (non-deleted)
$entries = $workspace->vaultEntries()->get();

// Find by key name
$entry = $workspace->vaultEntries()
    ->where('key_name', 'openai_api_key')
    ->first();

echo $entry->getCredentialKey(); // 'openai_api_key' (not Eloquent getKey(), which is the ULID)
echo $entry->isExpired();        // bool
</code-snippet>
@endverbatim

### Credential Key Naming Rules

Keys must match `/^[a-z][a-z0-9_]{1,98}[a-z0-9]$/` (snake_case, 3–100 chars, lowercase). Invalid keys throw `InvalidCredentialKeyException`.

@verbatim
<code-snippet name="Valid and invalid key names" lang="php">
// Valid
$workspace->storeCredential('openai_api_key', $value);
$workspace->storeCredential('stripe_secret_key', $value);
$workspace->storeCredential('github_token', $value);

// Invalid — throws InvalidCredentialKeyException
$workspace->storeCredential('OpenAI_Key', $value);  // uppercase
$workspace->storeCredential('my-key', $value);      // hyphens not allowed
$workspace->storeCredential('key', $value);          // too short (< 3 chars total logic)
</code-snippet>
@endverbatim

### Events

All events carry `ownerType`, `ownerId`, and `key` — never the raw credential value.

@verbatim
<code-snippet name="Listen to credential lifecycle events" lang="php">
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;

// In EventServiceProvider or a listener class
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

- **Do:** Use `hasCredential()` before `retrieveCredential()` in conditional logic to avoid null checks.
- **Do:** Store metadata (provider name, label, scopes) alongside credentials to support future listing UIs.
- **Do:** Use `rotateCredential()` / `Vault::rotate()` instead of delete + store to preserve `rotated_at` audit trail.
- **Do:** Use snake_case key names that describe the provider + purpose (e.g., `openai_api_key`, `stripe_webhook_secret`).
- **Don't:** Store decrypted values in logs, session, cache, or DTO `jsonSerialize()` output — the package never does this.
- **Don't:** Read `encrypted_value` directly from the model — always go through `Vault::retrieve()` or `retrieveCredential()`.
- **Don't:** Use the `VaultEntry` model directly for writes — always use the service or trait to ensure encryption.
