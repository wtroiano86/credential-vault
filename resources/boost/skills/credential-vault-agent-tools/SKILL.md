---
name: credential-vault-agent-tools
description: Integrate or troubleshoot the credential-vault inspection tools in Laravel AI SDK agents. Use for Gate authorization, key allowlists, pagination, queued contexts, and tool errors; use credential-vault-development for ordinary credential storage.
---

# Credential Vault agent tools

These runtime tools inspect stored credential names and status without selecting ciphertext/metadata, decrypting values, or writing entries. For ordinary application storage use `credential-vault-development`.

This skill is development guidance. Runtime restrictions come from the bundled handlers, their allowlist, and their Gate checks. They do not restrict direct vault-service calls, arbitrary PHP/shell execution, or access to ciphertext and application encryption keys. Keep an agent's other tools and execution permissions within its intended access.

Read `vendor/skillcraft/credential-vault/docs/agent-tools.md` for the full agent class and output contracts; in the package repository, use `docs/agent-tools.md`. Inspect existing policies and agent conventions before integrating.

## Install and authorize

```bash
composer require 'laravel/ai:^1.3'
```

The SDK is optional for ordinary vault operations. Neither installing it nor installing this Boost skill registers tools automatically. Add the tools explicitly to an agent implementing `Laravel\Ai\Contracts\HasTools`.

Define the application's `viewVaultCredential` Gate ability with owner and key arguments. Both an application allowlist and Gate authorization must permit each inspected key. For example, reuse an existing workspace policy:

```php
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Skillcraft\CredentialVault\Ai\VaultToolContext;

// Register in the application's provider; manageIntegrations is an app policy.
Gate::define(VaultToolContext::ABILITY, function (User $user, Workspace $workspace, string $key): bool {
    return $user->can('manageIntegrations', $workspace)
        && in_array($key, ['stripe_key', 'mailgun_key'], true);
});
```

Build a fresh context inside the agent's `tools()` method:

```php
use Illuminate\Support\Facades\Gate;
use Skillcraft\CredentialVault\Ai\Tools\GetCredentialStatus;
use Skillcraft\CredentialVault\Ai\Tools\ListCredentialKeys;
use Skillcraft\CredentialVault\Ai\VaultToolContext;

// Resolve $actor and $workspace from trusted application identity.
$context = new VaultToolContext(
    owner: $workspace,
    gate: Gate::forUser($actor),
    keys: ['stripe_key', 'mailgun_key'],
);

return [new ListCredentialKeys($context), new GetCredentialStatus($context)];
```

The owner must be persisted on the configured vault entry model's connection. `keys` must be a list of at most 100 input names before deduplication, each valid UTF-8, 1–255 bytes, without ASCII control bytes (`0x00`–`0x1F`, `0x7F`). Duplicate names are collapsed; an empty list grants no access. These inspection-name rules are independent of the service's configurable write-name pattern. Construction validates the key list; owner/connection validation and authorization happen on invocation.

Never obtain actor/owner IDs or broaden the allowlist from model-generated arguments. Gate checks run for every invocation; missing abilities deny access by default, subject to normal application Gate hooks. Policies must read current permissions when revocation can happen during a run.

For queued agents, serialize trusted actor/owner identifiers, establish tenant database context before reloading models, and reconstruct the context inside `tools()`. Do not serialize Gate/context objects or register contexts as singletons. The context retains model/Gate references; rebuild it when the actor, owner, or tenant connection context changes.

## Handle the tool contract

| Tool | Input | JSON string result |
|---|---|---|
| `vault_list_credential_keys` | Optional integer `limit` (1–50, default 25); optional string/null `after` | `keys`, `next_cursor`, `has_more` |
| `vault_get_credential_status` | Required string `key` | `key`, `stored`, `expired`, `expires_at`, `rotated_at` |

List results include authorized non-deleted keys, including expired entries, sorted by bytewise name comparison. Pass `next_cursor` as `after` for the next page. The cursor is a plain name boundary, not a signed token; it cannot broaden access. Permission changes and concurrent writes can change later pages.

Missing/deleted authorized entries report `stored: false` with null expiry/rotation fields. Status expiry is independent of `expired.return_null`. `stored: true` does not establish that ciphertext decrypts, a provider accepts the secret, or the credential has sufficient scopes.

| Error | Meaning and handling |
|---|---|
| `not_authorized` | Status key is outside the allowlist or denied by Gate. Discovery filters denied keys instead. Do not retry by broadening access. |
| `invalid_arguments` | Unknown fields, wrong types, invalid names, or page bounds. Numeric strings and null are invalid limits. Correct the caller to match the schema. |
| `unavailable` | Invalid owner, connection/query failure, or policy exception. Diagnose in trusted application code without exposing raw exception details to the model. |

Context construction errors propagate to application code. Tool handlers convert invocation failures to fixed JSON errors; they do not log underlying exception messages. The SDK allows string responses; JSON is this package's chosen format.

## Preserve the inspection boundary

- Tools query the configured `VaultEntry` subclass directly with its global scopes, excluding default relationship eager loading. They do not use custom `VaultServiceInterface` storage backends; such backends need their own inspection tools.
- Tools compare returned owner IDs/types and allowed names exactly, independently of database collation. Custom scopes, accessors, and observers are trusted application code and must preserve that boundary.
- Do not substitute `Vault::listKeys()` for the discovery tool: it includes caller-supplied metadata and is unbounded. Do not expose `retrieve()`, encrypted model attributes, or whole models in agent responses.
- These tools emit no vault lifecycle or access events. Inspect SDK `ToolInvoked` results for telemetry: error JSON is a completed invocation, not a thrown `ToolFailed`. Log selected fields, not entire tool/context objects.
- If the agent needs a provider operation, implement an application tool that authorizes the operation, selects the destination/credential on the server, retrieves the secret internally, and returns only the business result. Direct secret entry to the application's settings flow.

Test allowed and denied actors, owner isolation, forged extra arguments, expiry/deletion, pagination, permission changes between invocations, and queue reconstruction for the integration you change. Use the SDK's fake provider responses; keep real provider requests disabled in tests.
