## skillcraft/credential-vault — Advanced Usage

### Dependency Injection via VaultServiceInterface

Prefer injecting `VaultServiceInterface` directly in services or jobs for testability and to avoid facade coupling.

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

    public function storeApiKey(Model $workspace, string $key): void
    {
        $this->vault->store($workspace, 'openai_api_key', $key, [
            'provider' => 'openai',
        ]);
    }
}
</code-snippet>
@endverbatim

### Per-Tenant Encryption Keys

Enable per-tenant key derivation to isolate credential encryption per owner. Each owner gets a unique key derived via HKDF from the owner's ID + app key.

@verbatim
<code-snippet name="Enable per-tenant encryption in config" lang="php">
// config/general.php
'encryption' => [
    'per_tenant_key' => true, // Enables HKDF-derived key per owner
],
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Per-tenant encryption is transparent to callers" lang="php">
// No change in calling code — encryption is handled internally
$workspace->storeCredential('stripe_secret', 'sk_live_...');
$value = $workspace->retrieveCredential('stripe_secret');
// The encrypted blob in vault_entries is keyed to $workspace->id
</code-snippet>
@endverbatim

### Custom Encryption Implementation

Swap the encryption layer by binding a custom `VaultEncryptionInterface` implementation in config.

@verbatim
<code-snippet name="Register a custom encryption implementation" lang="php">
// config/general.php
'services' => [
    'vault' => \Skillcraft\CredentialVault\Services\VaultService::class,
    'encryption' => \App\Vault\AWSKmsVaultEncryption::class, // Custom implementation
],
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Custom VaultEncryptionInterface implementation skeleton" lang="php">
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;

final class AWSKmsVaultEncryption implements VaultEncryptionInterface
{
    public function encrypt(string $value, ?string $tenantKey = null): string
    {
        // Encrypt using AWS KMS, returning a base64-encoded ciphertext
    }

    public function decrypt(string $encrypted, ?string $tenantKey = null): string
    {
        // Decrypt using AWS KMS, returning plaintext
    }
}
</code-snippet>
@endverbatim

### Custom Vault Service Implementation

Swap the entire vault service — for example to add a caching layer or custom audit backend.

@verbatim
<code-snippet name="Register a custom VaultService implementation" lang="php">
// config/general.php
'services' => [
    'vault' => \App\Vault\CachedVaultService::class,
    'encryption' => \Skillcraft\CredentialVault\Services\LaravelVaultEncryption::class,
],
</code-snippet>
@endverbatim

### Credential Expiry

`VaultService::store()` persists `metadata` JSON and ciphertext only; it does **not** set the `expires_at` column. Set `expires_at` on the returned `VaultEntry` (or a follow-up update) if you use time-limited secrets. When `expires_at` is in the past, `retrieve()` returns `null` if `expired.return_null` is true.

@verbatim
<code-snippet name="Store then set expires_at on the vault entry" lang="php">
use Skillcraft\CredentialVault\Facades\Vault;

Vault::store($workspace, 'temp_access_token', $token, [
    'provider' => 'oauth',
]);

$workspace->vaultEntries()
    ->where('key_name', 'temp_access_token')
    ->update(['expires_at' => now()->addHour()]);

// After expiry, retrieve returns null when expired.return_null is true
$token = Vault::retrieve($workspace, 'temp_access_token');
</code-snippet>
@endverbatim

### VaultEntryData DTO

`VaultEntryData` is an immutable readonly DTO for passing vault entry metadata safely. It intentionally excludes the encrypted or decrypted value from JSON serialization.

@verbatim
<code-snippet name="Using VaultEntryData in API responses" lang="php">
use Skillcraft\CredentialVault\DTOs\VaultEntryData;

// Build a DTO from a VaultEntryInterface / VaultEntry model
$data = new VaultEntryData(
    key: $entry->getCredentialKey(),
    metadata: $entry->getMetadata(),
    createdAt: $entry->created_at?->toIso8601String() ?? '',
    rotatedAt: $entry->getRotatedAt()?->format(DATE_ATOM),
    expiresAt: $entry->getExpiresAt()?->format(DATE_ATOM),
);

// Safe to serialize — never exposes the credential value
return response()->json($data); // {"key":"openai_api_key","metadata":{...},...}
</code-snippet>
@endverbatim

### Integration with Skillcraft Workspace

When `skillcraft/workspace` is present, `Workspace` models can use `HasVault` directly.

@verbatim
<code-snippet name="HasVault on a multi-tenant Workspace model" lang="php">
use Skillcraft\CredentialVault\Traits\HasVault;
use Skillcraft\Workspace\Models\Workspace;

// Workspace model (in your application)
class Workspace extends Model
{
    use HasVault;
}

// In a service or controller
$workspace = Workspace::findOrFail($id);
$workspace->storeCredential('openai_api_key', $request->api_key, [
    'label' => $request->label,
]);
</code-snippet>
@endverbatim

### Event-Driven Audit Logging

When `audit.enabled = true`, `CredentialAccessed` is dispatched on every `retrieve()` call. Hook into this for compliance logging.

@verbatim
<code-snippet name="Audit logging via CredentialAccessed event" lang="php">
use Skillcraft\CredentialVault\Events\CredentialAccessed;

// In EventServiceProvider
protected $listen = [
    CredentialAccessed::class => [
        \App\Listeners\LogCredentialAccess::class,
    ],
];

// Listener
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

For read-only reporting, query the `VaultEntry` model directly. Never write through it — always use the service.

@verbatim
<code-snippet name="Query VaultEntry for reporting purposes" lang="php">
use Skillcraft\CredentialVault\Models\VaultEntry;

// All entries for a workspace, ordered by recency
$entries = VaultEntry::where('owner_type', Workspace::class)
    ->where('owner_id', $workspace->id)
    ->orderByDesc('created_at')
    ->get(['id', 'key_name', 'metadata', 'rotated_at', 'expires_at', 'created_at']);

// Check for entries not rotated in over 90 days
$stale = VaultEntry::where('owner_type', Workspace::class)
    ->where('owner_id', $workspace->id)
    ->where(function ($q) {
        $q->whereNull('rotated_at')
          ->orWhere('rotated_at', '<', now()->subDays(90));
    })
    ->get();
</code-snippet>
@endverbatim
