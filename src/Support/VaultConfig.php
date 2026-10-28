<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Support;

use Skillcraft\CredentialVault\Models\VaultEntry;

/** @internal */
final class VaultConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $legacyKey = 'packages.skillcraft-credential-vault.general.'.$key;

        return config()->has($legacyKey)
            ? config($legacyKey)
            : config('packages.credential-vault.general.'.$key, $default);
    }

    /** @return class-string<VaultEntry> */
    public static function model(): string
    {
        $class = self::get('model', VaultEntry::class);

        return is_string($class) && is_a($class, VaultEntry::class, true) ? $class : VaultEntry::class;
    }
}
