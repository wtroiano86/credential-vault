<?php

declare(strict_types=1);
use Skillcraft\CredentialVault\Models\VaultEntry;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Skillcraft\CredentialVault\Services\VaultService;

return [
    'services' => [
        'vault' => VaultService::class,
        'encryption' => LaravelVaultEncryption::class,
    ],

    'table' => 'scp_vault_entries',
    'model' => VaultEntry::class,

    'encryption' => [
        'per_tenant_key' => false,
        'allow_legacy' => false,
    ],

    'expired' => [
        'return_null' => true,
    ],

    'audit' => [
        'enabled' => true,
        'log_channel' => null,
    ],

    'allowed_key_patterns' => [
        '/\A[a-z][a-z0-9_]{1,98}[a-z0-9]\z/',
    ],
];
