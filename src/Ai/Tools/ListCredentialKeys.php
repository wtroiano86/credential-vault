<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use SensitiveParameter;
use Skillcraft\CredentialVault\Ai\InvalidToolArgumentsException;
use Skillcraft\CredentialVault\Ai\VaultToolContext;

final readonly class ListCredentialKeys extends VaultTool
{
    public function name(): string
    {
        return 'vault_list_credential_keys';
    }

    public function description(): string
    {
        return 'List credential names you are authorized to inspect for the application-selected owner. Returns keys, next_cursor, and has_more. Pass next_cursor as after to continue. Includes expired entries; excludes deleted entries. Never returns secret values or metadata. Presence does not verify provider access. Errors are invalid_arguments or unavailable.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(VaultToolContext::MAX_PAGE_SIZE)->default(25)
                ->description('Maximum number of keys to return; defaults to 25.'),
            'after' => $schema->string()->min(1)->max(255)->nullable()
                ->description('The next_cursor from the previous response, or null for the first page.'),
        ];
    }

    protected function execute(#[SensitiveParameter] array $arguments): array
    {
        $limit = $arguments['limit'] ?? 25;
        $after = $arguments['after'] ?? null;

        if (array_diff_key($arguments, ['limit' => true, 'after' => true]) !== []
            || ! is_int($limit) || ($after !== null && ! is_string($after))
            || (array_key_exists('limit', $arguments) && $arguments['limit'] === null)) {
            throw new InvalidToolArgumentsException('Invalid tool arguments.');
        }

        return $this->context->listKeys($limit, $after);
    }
}
