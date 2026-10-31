# Laravel AI agent tools

The package includes two optional tools for production agents using the [Laravel AI SDK](https://laravel.com/framework/docs/13.x/ai-sdk#tools). They inspect the configured Eloquent vault table without reading or decrypting credential values.

| Class | Tool name | Purpose |
|---|---|---|
| `Skillcraft\CredentialVault\Ai\Tools\ListCredentialKeys` | `vault_list_credential_keys` | List authorized, stored credential names with pagination. |
| `Skillcraft\CredentialVault\Ai\Tools\GetCredentialStatus` | `vault_get_credential_status` | Check presence, expiry, and last rotation time for an authorized key. |

## Installation

Install the supported SDK version in the consuming application:

```bash
composer require 'laravel/ai:^1.3'
```

Follow the SDK's installation/configuration instructions for your application's AI provider. `laravel/ai` is a suggested dependency of this package and a development dependency for its tests. It is not required for ordinary vault operations. No MCP server, routes, agent, or tools are registered automatically.

For coding-agent guidance while implementing this integration, the package also ships the `credential-vault-agent-tools` [Laravel Boost skill](../README.md#laravel-boost). Installing that skill does not install the SDK or register these runtime tools.

## Security boundary

These restrictions apply to the two bundled tool handlers. They do not restrict other tools, arbitrary PHP/shell execution, direct calls to `VaultServiceInterface`, or access to database ciphertext and application encryption keys. The core PHP service trusts application callers and does not check the tool context's Gate or allowlist. Keep production secrets and database credentials out of an agent's general execution environment, and expose provider operations through dedicated, authorized server-side tools. Boost skills and model instructions do not enforce runtime permissions.

The application-supplied owner, Gate, entry-model subclass, scopes, accessors, and observers are trusted code. Keep contexts scoped to one actor/owner run and review application Gate overrides; a permissive `before` hook can grant an allowlisted key even without a matching ability definition. The explicit key allowlist still applies.

## Authorization and registration

The application must construct a `VaultToolContext` with all three arguments:

- `owner`: a persisted Eloquent model on the configured vault entry model's database connection.
- `gate`: a Laravel Gate instance for the trusted actor, usually `Gate::forUser($user)`.
- `keys`: an explicit list of credential names this agent may inspect. At most 100 input names, before deduplication; each must be valid UTF-8, 1–255 bytes, without ASCII control bytes (`0x00`–`0x1F`, `0x7F`). Duplicate names are collapsed. An empty list grants no access. These inspection rules are independent of the service's configurable write-name patterns.

For each key, the tools also require the Gate ability `viewVaultCredential` with arguments `[$owner, $key]`. The allowlist does not replace authorization. A missing ability denies access by default. Laravel's normal Gate policies and `before` hooks apply; the host application is responsible for their decisions.

For example, register this ability in your application's service provider. The `manageIntegrations` policy must already enforce your application's workspace access rules; adapt it to your domain:

```php
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Skillcraft\CredentialVault\Ai\VaultToolContext;

Gate::define(VaultToolContext::ABILITY, function (User $user, Workspace $workspace, string $key): bool {
    return $user->can('manageIntegrations', $workspace)
        && in_array($key, ['stripe_key', 'mailgun_key'], true);
});
```

Then return the tools from your agent's `tools()` method. This example stores trusted identifiers and builds a fresh context when tools are resolved, so the agent can also be used in a queued job:

```php
<?php

namespace App\Ai\Agents;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Skillcraft\CredentialVault\Ai\Tools\GetCredentialStatus;
use Skillcraft\CredentialVault\Ai\Tools\ListCredentialKeys;
use Skillcraft\CredentialVault\Ai\VaultToolContext;

final class IntegrationSetupAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        public readonly int $userId,
        public readonly int $workspaceId,
    ) {}

    public function instructions(): string
    {
        return 'Help the user check integration setup. Use credential status tools. '
            .'Never request secret values in chat. Direct credential entry to the application settings. '
            .'A stored credential has not necessarily been validated by its provider.';
    }

    /** @return list<Tool> */
    public function tools(): iterable
    {
        $context = new VaultToolContext(
            owner: Workspace::findOrFail($this->workspaceId),
            gate: Gate::forUser(User::findOrFail($this->userId)),
            keys: ['stripe_key', 'mailgun_key'],
        );

        return [
            new ListCredentialKeys($context),
            new GetCredentialStatus($context),
        ];
    }
}
```

Derive the actor and workspace from trusted application authentication and routing when constructing the agent. Do not accept actor/owner IDs from model-generated tool arguments. The tool input schemas contain neither actor nor owner IDs, and handlers reject unknown arguments.

Authorization runs on every invocation, including subsequent pages and repeated use of a tool instance. Ensure policies read current permissions if permissions can change during a run. The context retains model/Gate references; rebuild it when the actor, owner, or tenant connection context changes. Create contexts per agent run; do not bind them as singletons or serialize a context/Gate into a queued agent. Queued jobs should establish tenant database context before reloading the actor/owner and constructing the context in `tools()`, as above.

The context constructor validates the key list. Owner persistence/connection validation and Gate evaluation happen when `listKeys()` or `status()` is invoked; constructing a context does not perform an authorization check.

## Inputs and outputs

The bundled handlers return JSON strings. The SDK's tool interface accepts `Stringable|string`; JSON is this package's response format. Fields are explicitly selected; responses contain no credential value, ciphertext, metadata, model object, or owner identifier. Schemas and tool descriptions do not enumerate the configured key allowlist.

### ListCredentialKeys

Optional arguments:

| Argument | Type | Behavior |
|---|---|---|
| `limit` | integer | 1–50; defaults to 25. Numeric strings, floats, and null are rejected. |
| `after` | string or null | Use the preceding response's `next_cursor`; omit or use null for the first page. It is a plain name boundary, not a signed token: any valid inspection name is accepted, and it cannot broaden the allowlist or Gate permissions. |

Example result:

```json
{"keys":["mailgun_key","stripe_key"],"next_cursor":null,"has_more":false}
```

Only stored, non-deleted keys that pass both the allowlist and Gate are returned. Expired entries are included. Results are sorted by credential name using bytewise string comparison. If `has_more` is true, pass `next_cursor` as `after` for the next page. Pagination is a live view: concurrent writes or permission changes can change later pages. The implementation evaluates at most 100 allowlisted keys and fetches at most 100 candidate rows per invocation; the returned page never exceeds 50 keys. No total count of unauthorized credentials is exposed.

### GetCredentialStatus

Required argument: `key` (string). The key must satisfy the inspection-name rules above, appear in the application allowlist, and pass its Gate check.

Stored entry:

```json
{"key":"stripe_key","stored":true,"expired":false,"expires_at":null,"rotated_at":"2026-10-22T12:00:00+00:00"}
```

Missing or soft-deleted entry:

```json
{"key":"stripe_key","stored":false,"expired":null,"expires_at":null,"rotated_at":null}
```

`expired` reflects the `expires_at` column at the time of inspection, independently of `expired.return_null`. Dates use `DATE_ATOM`. Presence does not verify decryption, provider validity, provider scopes, or provider revocation. Even corrupted or legacy ciphertext can report `stored: true`, because these tools do not decrypt it.

### Errors

| JSON result | Meaning |
|---|---|
| `{"error":"not_authorized"}` | The status key is outside the allowlist or denied by Gate. No lookup is performed, regardless of whether the key exists. |
| `{"error":"invalid_arguments"}` | Unknown arguments, wrong types, or invalid names/page bounds. |
| `{"error":"unavailable"}` | The configured owner is invalid, a connection/query fails, or a policy throws. |

Discovery filters denied keys and returns an empty page when none are allowed. Application/database exception messages, traces, and bindings are not returned or logged by the tools. Context construction errors propagate to application code, since they represent invalid application configuration. For trusted diagnostics, calling `VaultToolContext::listKeys()` or `status()` directly lets underlying exceptions propagate outside the model-facing handler.

## Production behavior

The tools query the configured `VaultEntry` model/subclass directly, respecting its global scopes and soft deletes while disabling default relationship eager loading. They share the service's persisted-owner, connection, and exact owner identity checks, and enforce exact allowed key names so permissive database collations cannot broaden access. Custom models should retain the package's unique owner/key constraint and attribute meanings. A custom `VaultServiceInterface` backed by a different storage system needs its own tools; these tools do not call that service's `listKeys()` or `retrieve()`.

No vault lifecycle or `CredentialAccessed` events are emitted, because no secrets are read and no entries are mutated. For agent audit records, use the SDK's `ToolInvoked` events and correlate the invocation with the trusted actor/owner in application code. An error JSON response is a completed SDK invocation, not a thrown `ToolFailed` event. These tools do not provide a durable audit store, rate limiter, or an agent execution budget; apply those controls in the application. SDK instrumentation may capture model-supplied arguments, so apply your own logging/redaction policy there. Log selected event fields rather than serializing entire event/tool objects, which contain the application-supplied context.

Secret retrieval, writes, rotation, deletion, generic HTTP requests, and provider operations are outside this tool set. When an agent needs to call a provider, expose a narrowly scoped application tool that authorizes the operation, retrieves the credential on the server, and returns a safe business result. Keep provider destinations and credential selection under application control.

## Testing

The package tests invoke handlers against real SQLite persistence and Laravel Gates, then exercise both tools through the SDK's actual agent loop with fake provider responses and HTTP requests blocked. A serialized SDK queue job is also executed to verify context reconstruction and permission changes before execution. Coverage includes denied access, owner isolation, permission changes, forged arguments, bounds/pagination, expired/deleted entries, custom models, case-insensitive collations, error redaction, and verification that ciphertext/metadata columns are never selected.
