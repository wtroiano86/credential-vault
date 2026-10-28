<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Ai\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use SensitiveParameter;
use Skillcraft\CredentialVault\Ai\InvalidToolArgumentsException;
use Skillcraft\CredentialVault\Ai\VaultToolContext;
use Throwable;

/** @internal Shared JSON response and error handling for the built-in tools. */
abstract readonly class VaultTool implements Tool
{
    public function __construct(protected VaultToolContext $context) {}

    final public function handle(#[SensitiveParameter] Request $request): string
    {
        try {
            return json_encode($this->execute($request->all()), JSON_THROW_ON_ERROR);
        } catch (InvalidToolArgumentsException) {
            // Invalid model input is an expected rejection with a distinct error result.
            return json_encode(['error' => 'invalid_arguments'], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Database/policy exceptions can contain application data. Do not send
            // messages, traces, or bindings to the model or log them here.
            return json_encode(['error' => 'unavailable'], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    abstract protected function execute(#[SensitiveParameter] array $arguments): array;
}
