## skillcraft/credential-vault — Troubleshooting

### Issue: `InvalidCredentialKeyException` on store/retrieve

**Symptom:** Calling `storeCredential()` or `Vault::store()` throws `InvalidCredentialKeyException`.

**Cause:** The key name does not match the configured pattern (`/^[a-z][a-z0-9_]{1,98}[a-z0-9]$/` by default). Keys must be lowercase snake_case, start and end with a letter or digit, and be 3–100 characters long.

@verbatim
<code-snippet name="Fix invalid credential key names" lang="php">
// Wrong — throws InvalidCredentialKeyException
$workspace->storeCredential('OpenAI-Key', $value);     // uppercase + hyphen
$workspace->storeCredential('_openai_key', $value);    // starts with underscore
$workspace->storeCredential('ok', $value);             // too short

// Correct
$workspace->storeCredential('openai_api_key', $value);
$workspace->storeCredential('stripe_secret_key', $value);
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

**Cause:** The `APP_KEY` was rotated after credentials were stored, or `per_tenant_key` was toggled on/off after data was written, making stored ciphertext undecryptable.

@verbatim
<code-snippet name="Recover from key rotation issues" lang="bash">
# There is no package artisan command to re-encrypt existing rows.
# After changing APP_KEY or toggling encryption.per_tenant_key on existing data,
# plan a custom migration/job to decrypt with the old key and re-store, or have
# owners re-enter secrets and call store/rotate again.
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Do not toggle per_tenant_key on existing data" lang="php">
// config/general.php
// WARNING: Changing this after credentials are stored will break decryption
// Decide at install time and do not change unless re-encrypting all entries
'encryption' => [
    'per_tenant_key' => false, // Set once, do not toggle
],
</code-snippet>
@endverbatim

---

### Issue: Credentials return `null` unexpectedly

**Symptom:** A previously stored credential returns `null` on retrieve.

**Cause:** No row exists, the row was soft-deleted, the wrong owner was passed, or the **`expires_at` column** on `vault_entries` is in the past. When `expired.return_null = true` (default), expired rows return `null` without decrypting. (Putting an expiry string only inside `metadata` does **not** enable this behavior unless you also set `expires_at` on the model.)

@verbatim
<code-snippet name="Check expiry state on a VaultEntry" lang="php">
use Skillcraft\CredentialVault\Models\VaultEntry;

$entry = $workspace->vaultEntries()
    ->where('key_name', 'temp_access_token')
    ->first();

if ($entry?->isExpired()) {
    // Rotate or re-store with a new expiry
    $workspace->rotateCredential('temp_access_token', $newToken);
}
</code-snippet>
@endverbatim

---

### Issue: `ConfigurationException`

**Symptom:** Your application throws `Skillcraft\CredentialVault\Exceptions\ConfigurationException`.

**Note:** The core package defines this exception for configuration errors but does not throw it from built-in services today. If you throw it from custom bindings or app code, verify config and encryption prerequisites.

**Typical causes when used from custom code:** invalid service class names in config, missing merge of package config, or unset `APP_KEY` for encryption.

@verbatim
<code-snippet name="Re-publish and verify configuration" lang="bash">
php artisan vendor:publish --tag=skillcraft-credential-vault-config --force
php artisan config:clear
php artisan cache:clear
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Verify required environment variables" lang="bash">
# APP_KEY must be set — used as the root encryption key
php artisan key:generate --show
# Set in .env:
# APP_KEY=base64:...
</code-snippet>
@endverbatim

---

### Issue: Migration not found / table does not exist

**Symptom:** `vault_entries` table missing; queries fail with "table does not exist".

@verbatim
<code-snippet name="Run vault migrations" lang="bash">
php artisan migrate

# If migrations are not discovered, re-publish them
php artisan vendor:publish --tag=skillcraft-credential-vault-migrations
php artisan migrate
</code-snippet>
@endverbatim

---

### Issue: Audit events not dispatching

**Symptom:** `CredentialAccessed` is not fired when retrieving credentials.

**Cause:** `audit.enabled` is `false` in config, or the queue is not running if listeners are queued.

@verbatim
<code-snippet name="Enable audit event dispatch" lang="php">
// config/general.php
'audit' => [
    'enabled' => true,        // Must be true for CredentialAccessed to dispatch on retrieve
    'log_channel' => null,    // Reserved — route logging in a CredentialAccessed listener
],
</code-snippet>
@endverbatim

---

### Performance Considerations

- **Avoid listing all keys in hot paths** — `listKeys()` / `listCredentialKeys()` fetches all entries for an owner. Cache the result if called frequently.
- **Avoid per-request decryption in loops** — Retrieve credentials once per request and pass the value rather than calling `retrieve()` repeatedly.
- **Index usage** — The `(owner_type, owner_id)` composite index is present by default. Ensure queries include both columns to hit the index.

@verbatim
<code-snippet name="Cache retrieved credentials within a request" lang="php">
// Use once() helper or a request-scoped cache to avoid redundant decryption
$apiKey = once(fn () => $workspace->retrieveCredential('openai_api_key'));
</code-snippet>
@endverbatim
