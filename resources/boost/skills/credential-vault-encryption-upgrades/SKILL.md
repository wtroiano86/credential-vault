---
name: credential-vault-encryption-upgrades
description: Upgrade existing credential-vault ciphertext to scv3 or diagnose decryption failures after key, mode, or owner/storage context changes. Use for credential-vault:upgrade-encryption and existing-data migrations; new empty installations need no ciphertext upgrade.
---

# Credential Vault encryption upgrades

Read `vendor/skillcraft/credential-vault/docs/credential-vault.md`, especially "Security and upgrading existing installations", in consuming applications; use `docs/credential-vault.md` in this repository. Establish the effective current and original configuration before changing encrypted data.

For a new installation with no existing ciphertext, run the normal schema migrations; no encryption-upgrade command is needed. For package development, validate upgrades using the disposable integration-test fixtures rather than assuming a consuming application exists.

## Identify the operation

New service writes use `scv3:` payloads bound to owner morph type, string owner ID, credential name, connection name, database name, table, and connection table prefix. The previous `scv2:` format omits the prefix. The built-in encryption driver uses an inner `scv1:` string format. Leave version markers intact.

| Situation | Supported approach |
|---|---|
| `scv2:` or older legacy ciphertext, same keys/cipher/mode/context | Use `credential-vault:upgrade-encryption`. It reads older formats without enabling application compatibility reads and adds table-prefix binding. This is required even with an empty prefix. |
| New payload fails to decrypt | Investigate original keys, cipher/mode, owner morph alias/ID, credential name, connection/database/table/prefix, or corruption. The upgrade command verifies new rows; it does not repair context mismatch. |
| Changing keys, mode, driver, identity, or database location | Plan a migration that reads with the original settings/context and re-stores with the new ones. The legacy-format command is not a general rekey/relocation tool. |

Default encryption uses Laravel's injected encrypter and its configured previous keys. Derived mode (`encryption.per_tenant_key = true`) uses only the current `app.key`, with HKDF-SHA-256 and a digest of credential context; it does not try previous keys. Never generate a new application key to repair unreadable existing ciphertext.

Use distinct authenticated storage contexts for tenants: schema-qualified table names for schema tenancy, or distinct connection names for otherwise identical databases on different servers. Implicit schema/search-path or server changes under the same connection/database/table/prefix do not distinguish tenants in the envelope.

## Prepare a legacy upgrade

1. Confirm a database backup and the original encryption keys are available. Pause credential reads/writes and old workers during the upgrade.
2. Preserve the original key, cipher, encryption mode, morph aliases, and connection/database/table/prefix settings. Review owner/key mappings and tenant placement: the command cannot detect substitution that happened before the relevant context was authenticated. `scv2:` validation cannot identify prior copies between table prefixes.
3. Inspect `packages.credential-vault.general` and any `packages.skillcraft-credential-vault.general` overrides; existing legacy values win for the requested service setting. Keep `encryption.allow_legacy = false` for normal operation.
4. Update published key patterns to `/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/` if still using the old default. Check that application callers now supply persisted owners on the vault model's connection.
5. Identify every tenant database, table prefix, and custom global scope. The command uses the configured entry subclass, includes soft-deleted rows, and retains other global scopes. Arrange tenant context so each intended row is visible in an invocation.

## Validate, then write

```bash
php artisan credential-vault:upgrade-encryption --dry-run
```

Dry run decrypts and prepares replacement ciphertext without saving. It still runs each row in a transaction and requests a row lock where supported, and it does not exercise save observers. Resolve failures before the write run; do not print secret values, encryption keys, or raw driver exceptions while investigating.

```bash
php artisan credential-vault:upgrade-encryption
```

- Rows are processed in batches of 100, each in its own transaction. Expired and soft-deleted entries are included.
- The command changes ciphertext with automatic timestamps disabled, preserving metadata, expiry, rotation/deletion state, and timestamps under the default model. Custom save observers run and may change or veto persistence.
- Per-row failures leave that row unchanged and produce a nonzero exit status. Earlier successful rows remain committed. Correct the cause and rerun; `scv3:` rows are verified and skipped. `scv2:` rows are validated against their original context before re-encryption.
- Output reports `Would upgrade` or `Upgraded` and `Failed` counts without per-row secret/exception details. No credential lifecycle events are emitted.

Confirm a successful exit and `Failed: 0` for every required tenant/scope. That count covers only visible rows. Keep compatibility reads disabled before resuming traffic. Older package code cannot read the new format; rollback needs a coordinated data/key backup restore.

Temporary `encryption.allow_legacy = true` permits `scv2:` and older legacy reads for an explicitly staged rollout, retaining their original substitution risks until upgraded; `scv2:` does not authenticate table prefixes. It does not enable PHP object deserialization, accept removed/changed version markers, or repair corrupted/context-mismatched payloads.

## Verify a migration implementation

Test on disposable data or a controlled copy with the original settings. Cover legacy and already upgraded rows, expired/deleted entries, custom scopes, partial failures, and reruns. Confirm failed rows remain unchanged and successful upgrades preserve lifecycle fields. A custom encryption driver must authenticate ciphertext, preserve arbitrary string bytes, read its own legacy format for this command, and redact concrete plaintext parameters with `#[\SensitiveParameter]`.
