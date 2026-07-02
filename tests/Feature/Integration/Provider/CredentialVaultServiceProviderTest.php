<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Provider;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Providers\CredentialVaultServiceProvider;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

final class CredentialVaultServiceProviderTest extends CredentialVaultTestCase
{
    public function test_vault_encryption_interface_resolves_to_default_laravel_vault_encryption_class(): void
    {
        $this->assertInstanceOf(LaravelVaultEncryption::class, app(VaultEncryptionInterface::class));
    }

    public function test_vault_encryption_interface_is_bound_as_singleton(): void
    {
        $a = app(VaultEncryptionInterface::class);
        $b = app(VaultEncryptionInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_vault_encryption_interface_resolves_to_custom_class_when_overridden_in_config(): void
    {
        $customClass = new class implements VaultEncryptionInterface
        {
            public function encrypt(string $value, ?string $tenantKey = null): string
            {
                return 'enc';
            }

            public function decrypt(string $encrypted, ?string $tenantKey = null): string
            {
                return 'dec';
            }
        };

        $customClassName = $customClass::class;

        app()->bind($customClassName, fn () => $customClass);

        config(['packages.credential-vault.general.services.encryption' => $customClassName]);

        app()->forgetInstance(VaultEncryptionInterface::class);

        $provider = new CredentialVaultServiceProvider(app());
        $provider->register();

        $resolved = app(VaultEncryptionInterface::class);

        $this->assertInstanceOf(VaultEncryptionInterface::class, $resolved);
    }

    public function test_vault_service_interface_resolves_to_registered_implementation(): void
    {
        $resolved = app(VaultServiceInterface::class);

        $this->assertInstanceOf(VaultServiceInterface::class, $resolved);
    }

    public function test_vault_service_interface_is_bound_as_singleton(): void
    {
        $a = app(VaultServiceInterface::class);
        $b = app(VaultServiceInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_vault_service_interface_resolves_to_custom_class_when_overridden_in_config(): void
    {
        $customClass = new class implements VaultServiceInterface
        {
            public function store(Model $o, string $k, string $v, array $m = []): VaultEntryInterface
            {
                return Mockery::mock(VaultEntryInterface::class);
            }

            public function retrieve(Model $o, string $k): ?string
            {
                return null;
            }

            public function delete(Model $o, string $k): void {}

            public function rotate(Model $o, string $k, string $n): VaultEntryInterface
            {
                return Mockery::mock(VaultEntryInterface::class);
            }

            public function exists(Model $o, string $k): bool
            {
                return false;
            }

            public function listKeys(Model $o): array
            {
                return [];
            }
        };

        $customClassName = $customClass::class;

        app()->bind($customClassName, fn () => $customClass);

        config(['packages.credential-vault.general.services.vault' => $customClassName]);

        app()->forgetInstance(VaultServiceInterface::class);

        $provider = new CredentialVaultServiceProvider(app());
        $provider->register();

        $resolved = app(VaultServiceInterface::class);

        $this->assertInstanceOf(VaultServiceInterface::class, $resolved);
    }

    public function test_boot_loads_vault_entries_migration_file(): void
    {
        $migrationPath = base_path('platform/packages/credential-vault/database/migrations/2026_02_21_000000_create_vault_entries_table.php');

        $migrationFile = realpath($migrationPath);

        $this->assertNotFalse($migrationFile);
        $this->assertIsString($migrationFile);
        $this->assertFileExists($migrationFile);
    }
}
