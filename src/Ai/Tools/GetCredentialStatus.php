<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use SensitiveParameter;
use Skillcraft\CredentialVault\Ai\InvalidToolArgumentsException;

final readonly class GetCredentialStatus extends VaultTool
{
    public function name(): string
    {
        return 'vault_get_credential_status';
    }

    public function description(): string
    {
        return 'Inspect an authorized credential for the application-selected owner. Returns key, stored, expired, expires_at, and rotated_at. Missing or deleted entries have stored=false and null status dates/expiry. Does not decrypt, verify provider access, or return values or metadata. Errors are not_authorized, invalid_arguments, or unavailable.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->min(1)->max(255)->required()->description('Credential name to inspect.'),
        ];
    }

    protected function execute(#[SensitiveParameter] array $arguments): array
    {
        $key = $arguments['key'] ?? null;

        if (array_diff_key($arguments, ['key' => true]) !== [] || ! is_string($key)) {
            throw new InvalidToolArgumentsException('Invalid tool arguments.');
        }

        return $this->context->status($key);
    }
}
