## skillcraft/credential-vault — Troubleshooting

### Issue: `InvalidCredentialKeyException` on store/rotate

**Symptom:** Calling `storeCredential()` / `Vault::store()` or `rotateCredential()` / `Vault::rotate()` throws `InvalidCredentialKeyException`. Retrieval does not validate key names.

**Cause:** The name does not match any configured pattern (`/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/` by default). Names must be 3–100 characters, begin with a lowercase letter, end with a lowercase letter or digit, and contain only lowercase letters, digits, and underscores. An empty pattern list rejects every store or rotation.

@verbatim
<code-snippet name="Fix invalid credential key names" lang="php">
// Wrong — throws InvalidCredentialKeyException
$workspace->storeCredential('OpenAI-Key', $value);     // uppercase + hyphen
$workspace->storeCredential('_openai_key', $value);    // starts with underscore
$workspace->storeCredential('ok', $value);             // too short
$workspace->storeCredential('1key', $value);           // starts with a digit

// Correct
$workspace->storeCredential('openai_api_key', $value);
$workspace->storeCredential('stripe_secret_key', $value);
$workspace->storeCredential('key', $value);            // three letters is valid
</code-snippet>
@endverbatim

---

### Issue: `CredentialNotFoundException` when rotating

**Symptom:** `CredentialNotFoundException` is thrown when calling `rotateCredential()` or `Vault::rotate()`.

**Cause:** There is no non-deleted vault entry for that owner and key. **`retrieve()` / `retrieveCredential()` do not throw this** — they return `null` when missing or when expired with `expired.return_null` enabled.

@verbatim
<code-snippet name="Guard rotate vs handle null on retrieve" lang="php">
// retrieve: null when missing or expired (no CredentialNotFoundException)
$value = $workspace->retrieveCredential('openai_api_key');

if ($value === null) {
    throw new \RuntimeException('OpenAI API key not configured for this workspace.');
}

// rotate: throws CredentialNotFoundException if the key was never stored
if ($workspace->hasCredential('openai_api_key')) {
    $workspace->rotateCredential('openai_api_key', $newKey);
}
</code-snippet>
@endverbatim

---

### Issue: `CryptoOperationException` on decrypt

**Symptom:** Retrieving a credential throws `CryptoOperationException`.

**Possible causes:** The payload is damaged, the encryption mode or cipher changed, the original application key is unavailable, the owner/name/database context changed, or the row still uses the legacy format (disabled by default). Per-owner mode derives from the current `app.key` only; it does not try previous keys. Default mode uses Laravel's injected encrypter, including configured previous keys.

@verbatim
<code-snippet name="Recover from key rotation issues" lang="bash">
# Upgrade legacy ciphertext under the original key, cipher, and mode:
php artisan credential-vault:upgrade-encryption --dry-run
php artisan credential-vault:upgrade-encryption
# This command does not change encryption keys or modes.
# After changing APP_KEY or toggling encryption.per_tenant_key on existing data,
# plan a custom migration/job to decrypt with the old key and re-store, or have
# owners re-enter secrets and call store/rotate again.
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Do not toggle per_tenant_key on existing data" lang="php">
// In config/packages.credential-vault.general.php
// WARNING: Changing this after credentials are stored will break decryption
// Decide at install time and do not change unless re-encrypting all entries
'encryption' => [
    'per_tenant_key' => false, // Set once, do not toggle
    'allow_legacy' => false,
],
</code-snippet>
@endverbatim

---

### Issue: Credentials return `null` unexpectedly

**Symptom:** A previously stored credential returns `null` on retrieve.

**Cause:** No row exists, the row was soft-deleted, the wrong owner was passed, or the **`expires_at` column** on `scp_vault_entries` has reached the current time. When `expired.return_null = true` (default), expired rows return `null` without decrypting. Putting an expiry inside `metadata` does not set this column. `hasCredential()` can return true for an expired entry.

@verbatim
<code-snippet name="Check expiry state on a VaultEntry" lang="php">
$entry = $workspace->vaultEntries()
    ->where('key_name', 'temp_access_token')
    ->first();

if ($entry?->isExpired()) {
    // Rotation preserves the old expiry, so renew it explicitly
    $workspace->rotateCredential('temp_access_token', $newToken);
    $entry->update(['expires_at' => now()->addHour()]);
}
</code-snippet>
@endverbatim

---

### Issue: `ConfigurationException`

**Symptom:** Your application throws `Skillcraft\CredentialVault\Exceptions\ConfigurationException`.

**Cause:** Key pattern configuration is malformed, or the provider resolved a class from `services.encryption` or `services.vault` that does not implement the corresponding contract. Configure implementations of `VaultEncryptionInterface` and `VaultServiceInterface`. Missing or unresolvable classes instead fail during Laravel container resolution.

Edit `config/packages.credential-vault.general.php`, and check for stale `packages.skillcraft-credential-vault.general.services.*` overrides: existing legacy values take precedence.

@verbatim
<code-snippet name="Default service implementations" lang="php">
// In config/packages.credential-vault.general.php
'services' => [
    'vault' => \Skillcraft\CredentialVault\Services\VaultService::class,
    'encryption' => \Skillcraft\CredentialVault\Services\LaravelVaultEncryption::class,
],
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Reload configuration after correcting service classes" lang="bash">
php artisan config:clear
</code-snippet>
@endverbatim

An absent or invalid application encryption key is a separate issue. Laravel may fail while resolving its encrypter; per-owner key derivation reports `CryptoOperationException`. Preserve the key used for existing ciphertext when repairing configuration.

For legacy ciphertext migration, use the `credential-vault-encryption-upgrades` skill. The upgrade command retains custom global scopes and dry run does not execute save observers; a successful dry run alone does not prove every tenant's rows were upgraded.

---

### Issue: Migration not found / table does not exist

**Symptom:** `scp_vault_entries` (or your configured table) is missing; queries fail with "table does not exist".

Verify that `CredentialVaultServiceProvider` is registered through package discovery and that `packages.credential-vault.general.table` matches the migrated table. Migrations load automatically. Changing the table config after migration does not rename the existing table.

@verbatim
<code-snippet name="Run vault migrations" lang="bash">
php artisan migrate

# Optional: copy migrations to your application for customization
php artisan vendor:publish --tag=skillcraft-credential-vault-migrations
php artisan migrate
</code-snippet>
@endverbatim

---

### Issue: Storage cancelled or owner rejected

`InvalidVaultOwnerException` means the owner is unsaved, has an invalid primary key, or uses a different database connection from the configured vault entry model. Persist the owner and use a vault model on that connection. Sharing a central vault across owner connections requires a custom service with an explicit identity scheme.

`CredentialPersistenceException` means a model observer vetoed a save, restoration, rotation, or deletion. The transaction rolls back and no mutation event is dispatched. Inspect the observer; do not treat a veto as a successful write.

`store()` automatically restores a soft-deleted owner/key row with the new value and metadata, clearing its old expiry and rotation time. Active entries retain those timestamps.

@verbatim
<code-snippet name="Store a replacement for a deleted credential" lang="php">
$workspace->storeCredential('openai_api_key', $newKey);
</code-snippet>
@endverbatim

---

### Issue: Audit events not dispatching

**Symptom:** `CredentialAccessed` is not fired when retrieving credentials.

**Cause:** `audit.enabled` is false, or retrieval returns `null` or fails before decryption succeeds. Also check legacy config overrides. A stopped queue prevents queued listeners from running, but does not prevent event dispatch. Store, rotation, and deletion events are independent of `audit.enabled`; they wait for the outer transaction to commit. Rollback, a model veto, and deletion of a missing row emit no mutation event.

@verbatim
<code-snippet name="Enable audit event dispatch" lang="php">
// In config/packages.credential-vault.general.php
'audit' => [
    'enabled' => true,        // Dispatch CredentialAccessed after successful decryption
    'log_channel' => null,    // Reserved — route logging in a CredentialAccessed listener
],
</code-snippet>
@endverbatim

---

### Performance Considerations

- **Avoid listing all keys in hot paths** — `listKeys()` / `listCredentialKeys()` fetches every non-deleted entry for an owner, including expired ones. Reuse the result when possible.
- **Avoid per-request decryption in loops** — Retrieve credentials once per request and pass the value rather than calling `retrieve()` repeatedly.
- **Index usage** — The `(owner_type, owner_id)` composite index is present by default. Ensure queries include both columns to hit the index.

@verbatim
<code-snippet name="Retrieve a credential once for an owner" lang="php">
$apiKey = $workspace->retrieveCredential('openai_api_key');
// Reuse this local value for this owner's operations during the request
</code-snippet>
@endverbatim

### Issue: Agent credential tools return errors or no keys

- `not_authorized`: status requested a key outside the application allowlist or denied by `viewVaultCredential`. Use a Gate instance for the trusted actor and define the ability/policy with owner and key arguments. Discovery silently filters denied keys.
- `invalid_arguments`: check the tool schema. List limits are integers from 1 to 50; status requires a string `key`. Unknown fields, including owner IDs and requests for values, are rejected.
- `unavailable`: inspect owner persistence, the configured entry model/connection, and policy/query failures in trusted application code. Raw exception details are deliberately excluded from model responses.
- `Laravel\Ai\Contracts\Tool` missing: install the optional `laravel/ai:^1.3` dependency before registering tools.

A stored credential may be expired, corrupt, or revoked by its provider. These tools report storage state without decryption or a provider request. They emit no vault access event; inspect SDK `ToolInvoked` results, including error JSON, for agent telemetry. See `docs/agent-tools.md` for setup and diagnosis.
