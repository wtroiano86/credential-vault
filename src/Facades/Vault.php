<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;

/**
 * Facade for the Credential Vault service.
 *
 * Provides a convenient static interface for internal package-to-package
 * communication.  All methods delegate to the bound {@see VaultServiceInterface}.
 *
 * @method static VaultEntryInterface store(Model $owner, string $key, string $value, array<string, mixed> $metadata = [])
 * @method static string|null retrieve(Model $owner, string $key)
 * @method static void delete(Model $owner, string $key)
 * @method static VaultEntryInterface rotate(Model $owner, string $key, string $newValue)
 * @method static bool exists(Model $owner, string $key)
 * @method static array<int, array{key: string, metadata: array<string, mixed>, created_at: string, rotated_at: ?string}> listKeys(Model $owner)
 *
 * @see VaultServiceInterface
 */
final class Vault extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return VaultServiceInterface::class;
    }
}
