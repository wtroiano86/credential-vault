## skillcraft/credential-vault — Advanced Usage

### Dependency Injection via VaultServiceInterface

Prefer injecting `VaultServiceInterface` directly in services or jobs for testability and to avoid facade coupling. The caller must authorize the actor's access to the owner/key before invoking these methods; this example assumes that has happened and is not an AI tool registration.

@verbatim
<code-snippet name="Inject VaultServiceInterface in a service class" lang="php">
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Illuminate\Database\Eloquent\Model;

class OpenAiClient
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function getApiKey(Model $workspace): ?string
    {
        return $this->vault->retrieve($workspace, 'openai_api_key');
    }

    public function storeApiKey(Model $workspace, #[\SensitiveParameter] string $key): void
    {
        $this->vault->store($workspace, 'openai_api_key', $key, [
            'provider' => 'openai',
        ]);
    }
}
</code-snippet>
@endverbatim

### Per-Tenant Encryption Keys

Enable per-tenant key derivation to derive encryption keys with HKDF-SHA-256 from the current `app.key`, using a digest of the owner type/ID, credential name, and vault connection/database/table/prefix as salt. The service authenticates this context in both encryption modes, so ciphertext copied to another context is rejected. Every operation requires a persisted owner with an integer or non-empty string ID on the vault model's connection; there is no shared-key fallback for invalid owners.

@verbatim
<code-snippet name="Enable per-tenant encryption in config" lang="php">
// In config/packages.credential-vault.general.php
'encryption' => [
    'per_tenant_key' => true, // Enables HKDF-derived key per credential context
    'allow_legacy' => false,
],
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Per-tenant encryption is transparent to callers" lang="php">
// No change in calling code — encryption is handled internally
$workspace->storeCredential('stripe_secret', 'sk_live_...');
$value = $workspace->retrieveCredential('stripe_secret');
// Derivation binds owner type/ID, credential name, and connection/database/table/prefix.
</code-snippet>
@endverbatim

The derived encrypter uses `app.cipher` and only the current `app.key`; it does not try Laravel's previous keys. The default mode uses the injected Laravel encrypter, including its configured previous keys. Changing modes or derived-key inputs requires a custom migration that reads under the old settings and re-stores under the new ones. `credential-vault:upgrade-encryption` upgrades `scv2:` and older payloads to `scv3:` under unchanged settings, adding table-prefix binding. Run it for every tenant prefix before resuming traffic.

### Custom Encryption Implementation

Swap the encryption layer by binding a custom `VaultEncryptionInterface` implementation in config. Preserve arbitrary string bytes and authenticate ciphertext. Add `#[\SensitiveParameter]` to concrete secret parameters; interface attributes are not inherited. The service wraps values in an authenticated context envelope, including when using a custom driver. The KMS class below is an application implementation skeleton, not a bundled adapter.

@verbatim
<code-snippet name="Register a custom encryption implementation" lang="php">
// In config/packages.credential-vault.general.php
'services' => [
    'vault' => \Skillcraft\CredentialVault\Services\VaultService::class,
    'encryption' => \App\Vault\AWSKmsVaultEncryption::class, // Custom implementation
],
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Custom VaultEncryptionInterface implementation skeleton" lang="php">
namespace App\Vault;

use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;

final class AWSKmsVaultEncryption implements VaultEncryptionInterface
{
    public function encrypt(#[\SensitiveParameter] string $value, ?string $tenantKey = null): string
    {
        // Replace with your KMS adapter, returning a string payload.
        throw new \LogicException('Implement KMS encryption.');
    }

    public function decrypt(string $encrypted, ?string $tenantKey = null): string
    {
        // Replace with your KMS adapter, returning the plaintext string.
        throw new \LogicException('Implement KMS decryption.');
    }
}
</code-snippet>
@endverbatim

### Custom Vault Service Implementation

Select a class implementing `VaultServiceInterface` to customize storage or auditing. `VaultService` and `LaravelVaultEncryption` are final readonly classes; implement their contracts or compose them rather than subclassing them. The provider binds both interfaces as singletons and throws `ConfigurationException` if a resolved class does not implement the expected contract.

@verbatim
<code-snippet name="Register a custom VaultService implementation" lang="php">
// In config/packages.credential-vault.general.php
'services' => [
    'vault' => \App\Vault\AuditedVaultService::class, // Application-provided implementation
    'encryption' => \Skillcraft\CredentialVault\Services\LaravelVaultEncryption::class,
],
</code-snippet>
@endverbatim

### Custom Entry Model

Set `packages.credential-vault.general.model` to `VaultEntry` or a subclass to change the model used by `VaultService`. Invalid values fall back to the base model. `HasVault::vaultEntries()` still uses the base model; override the relationship on the owner if you need it to use the custom class. Set `packages.credential-vault.general.table` before migrating to change the table used by the base model and package migrations.

### Credential Expiry

`VaultService::store()` writes ciphertext and metadata but has no expiry argument. Set `expires_at` on the returned Eloquent entry. At or after expiry, `retrieve()` returns `null` when `expired.return_null` is true; setting it to false allows decryption normally. Updates to non-deleted rows through `store()` or `rotate()` preserve expiry, including already-expired timestamps; restoring a deleted entry clears it. `exists()` and `listKeys()` include expired entries. Storage and the expiry update are separate writes; wrap both in a transaction on the vault connection if they must be atomic.

@verbatim
<code-snippet name="Store then set expires_at on the vault entry" lang="php">
use Skillcraft\CredentialVault\Facades\Vault;
use Skillcraft\CredentialVault\Models\VaultEntry;

$entry = Vault::store($workspace, 'temp_access_token', $token, [
    'provider' => 'oauth',
]);

if (! $entry instanceof VaultEntry) {
    throw new \LogicException('This expiry example requires the Eloquent vault implementation.');
}
$entry->update(['expires_at' => now()->addHour()]);

// After expiry, retrieve returns null when expired.return_null is true
$token = Vault::retrieve($workspace, 'temp_access_token');
</code-snippet>
@endverbatim

### VaultEntryData DTO

`VaultEntryData` is a final readonly DTO with `key`, `metadata`, `created_at`, `rotated_at`, and `expires_at` in its JSON output. It has no credential-value field, but metadata is not scrubbed for secrets. `listKeys()` returns arrays rather than these DTOs, and omits `expires_at`.

@verbatim
<code-snippet name="Using VaultEntryData in API responses" lang="php">
use Skillcraft\CredentialVault\DTOs\VaultEntryData;

// $entry is a VaultEntry model; the interface alone does not expose created_at
$data = new VaultEntryData(
    key: $entry->getCredentialKey(),
    metadata: $entry->getMetadata(),
    createdAt: $entry->created_at?->toIso8601String() ?? '',
    rotatedAt: $entry->getRotatedAt()?->format(DATE_ATOM),
    expiresAt: $entry->getExpiresAt()?->format(DATE_ATOM),
);

// Keep the supplied metadata free of secrets
return response()->json($data); // {"key":"openai_api_key","metadata":{...},...}
</code-snippet>
@endverbatim

### Integration with Owner Models

Any persisted Eloquent model on the vault entry model's database connection using `HasVault` or extending `VaultOwner` can own credentials. No workspace package is required.

@verbatim
<code-snippet name="Store a credential for an existing workspace" lang="php">
use App\Models\Workspace; // Your model uses HasVault or extends VaultOwner
use Illuminate\Support\Facades\Gate;

// In a service or controller
$workspace = Workspace::findOrFail($id);
Gate::authorize('manageIntegrations', $workspace); // Define this application policy.
$workspace->storeCredential('openai_api_key', $request->api_key, [
    'label' => $request->label,
]);
</code-snippet>
@endverbatim

### Event-Driven Audit Logging

When `audit.enabled = true`, `CredentialAccessed` is dispatched after a successful decryption in `retrieve()`. Null returns and failures before dispatch, including decryption failures, emit no access event. A synchronous listener can throw after dispatch begins and prevent plaintext from being returned. Configure logging in your listener; `audit.log_channel` is unused by the package. Define the example `audit` logging channel in the application. Event fields identify the owner/key, not the acting user, and delivery is not a durable audit guarantee.

@verbatim
<code-snippet name="Register a credential access listener" lang="php">
use App\Listeners\LogCredentialAccess;
use Illuminate\Support\Facades\Event;
use Skillcraft\CredentialVault\Events\CredentialAccessed;

// In your application service provider's boot() method
Event::listen(CredentialAccessed::class, LogCredentialAccess::class);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Audit logging via CredentialAccessed event" lang="php">
namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Skillcraft\CredentialVault\Events\CredentialAccessed;

class LogCredentialAccess
{
    public function handle(CredentialAccessed $event): void
    {
        Log::channel('audit')->info('Credential accessed', [
            'owner_type' => $event->ownerType,
            'owner_id'   => $event->ownerId,
            'key'        => $event->key,
            'accessed_at' => now()->toIso8601String(),
        ]);
    }
}
</code-snippet>
@endverbatim

### Direct VaultEntry Eloquent Queries

For trusted reporting, authorize the caller, query by both morph class and owner key, and check returned identities exactly because database collations can broaden matches. Direct queries/updates bypass service validation, exact comparisons, encryption, expiry filtering, and credential events. Use the service for secret values and restoration; prefer its returned model for expiry updates. If a custom entry model is configured, query that class instead. These reporting results include metadata and must not replace the restricted AI tools.

@verbatim
<code-snippet name="Query VaultEntry for reporting purposes" lang="php">
use Skillcraft\CredentialVault\Models\VaultEntry;

// $workspace is loaded and authorized; use the configured subclass if overridden.
$entries = VaultEntry::where('owner_type', $workspace->getMorphClass())
    ->where('owner_id', (string) $workspace->getKey())
    ->orderByDesc('created_at')
    ->get(['id', 'owner_type', 'owner_id', 'key_name', 'metadata', 'rotated_at', 'expires_at', 'created_at'])
    ->filter(static fn (VaultEntry $entry): bool =>
        $entry->getOwnerType() === $workspace->getMorphClass()
        && $entry->getOwnerId() === (string) $workspace->getKey()
    )->values();

// Filter the verified rows for entries never rotated or rotated over 90 days ago.
$cutoff = now()->subDays(90)->getTimestamp();
$stale = $entries->filter(static fn (VaultEntry $entry): bool =>
    $entry->getRotatedAt() === null || $entry->getRotatedAt()->getTimestamp() < $cutoff
)->values();
</code-snippet>
@endverbatim

### Production Agent Tool Registration

The optional tools require Laravel AI SDK `^1.3`. In an agent implementing `HasTools`, create a context using the application's authenticated actor and selected owner. Define the `viewVaultCredential` Gate ability/policy first; its arguments are the owner and credential key. Undefined abilities deny access unless an application Gate hook grants it. The explicit allowlist always applies.

@verbatim
<code-snippet name="Register credential inspection tools on a Laravel AI agent" lang="php">
use Illuminate\Support\Facades\Gate;
use Skillcraft\CredentialVault\Ai\Tools\GetCredentialStatus;
use Skillcraft\CredentialVault\Ai\Tools\ListCredentialKeys;
use Skillcraft\CredentialVault\Ai\VaultToolContext;

$context = new VaultToolContext(
    owner: $workspace,
    gate: Gate::forUser($user),
    keys: ['stripe_key', 'mailgun_key'],
);

// Return these from the agent's tools() method.
return [new ListCredentialKeys($context), new GetCredentialStatus($context)];
</code-snippet>
@endverbatim

For queued agents, store trusted actor/owner identifiers, establish tenant database context before reloading models, and construct the context inside `tools()`. Do not serialize Gate/context instances or bind them as singletons. Gate permissions are checked on every invocation, so policies should use current authorization data. SDK `ToolInvoked` events provide invocation telemetry; the vault's `CredentialAccessed` event does not fire for status/discovery. The tools read the configured Eloquent model, not a custom service's alternative backend. Their restrictions do not govern direct service calls or arbitrary application-code execution. See `docs/agent-tools.md`.
