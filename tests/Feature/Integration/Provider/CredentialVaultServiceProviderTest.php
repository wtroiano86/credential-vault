<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Provider;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Exceptions\ConfigurationException;
use Skillcraft\CredentialVault\Providers\CredentialVaultServiceProvider;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

final class CredentialVaultServiceProviderTest extends CredentialVaultTestCase
{
    public function test_register_merges_default_config_without_overwriting_application_values(): void
    {
        config(['packages.credential-vault.general' => ['table' => 'custom_vault_entries']]);

        $provider = new CredentialVaultServiceProvider(app());
        $provider->register();

        $this->assertSame('custom_vault_entries', config('packages.credential-vault.general.table'));
        $this->assertSame(LaravelVaultEncryption::class, config('packages.credential-vault.general.services.encryption'));
        $this->assertTrue(config('packages.credential-vault.general.audit.enabled'));
    }

    #[DataProvider('configPublishTags')]
    public function test_config_can_be_published_using_supported_tags(string $tag): void
    {
        $this->assertSame([
            dirname(__DIR__, 4).'/config/general.php' => config_path('packages.credential-vault.general.php'),
        ], ServiceProvider::pathsToPublish(CredentialVaultServiceProvider::class, $tag));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function configPublishTags(): array
    {
        return [
            'existing tag' => ['credential-vault-config'],
            'documented tag' => ['skillcraft-credential-vault-config'],
        ];
    }

    #[DataProvider('migrationPublishTags')]
    public function test_migrations_can_be_published_using_supported_tags(string $tag): void
    {
        $this->assertSame([
            dirname(__DIR__, 4).'/database/migrations' => database_path('migrations'),
        ], ServiceProvider::pathsToPublish(CredentialVaultServiceProvider::class, $tag));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function migrationPublishTags(): array
    {
        return [
            'existing tag' => ['credential-vault-migrations'],
            'documented tag' => ['skillcraft-credential-vault-migrations'],
        ];
    }

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

    public function test_boot_loads_and_runs_package_migrations(): void
    {
        $this->assertContains(dirname(__DIR__, 4).'/database/migrations', app('migrator')->paths());
        $this->assertTrue(Schema::hasTable('scp_vault_entries'));
        $this->assertTrue(Schema::hasIndex('scp_vault_entries', 'vault_entries_owner_index'));
    }

    public function test_encryption_binding_throws_configuration_exception_for_invalid_class(): void
    {
        config(['packages.skillcraft-credential-vault.general.services.encryption' => \stdClass::class]);

        app()->forgetInstance(VaultEncryptionInterface::class);

        $provider = new CredentialVaultServiceProvider(app());
        $provider->register();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(VaultEncryptionInterface::class);

        app(VaultEncryptionInterface::class);
    }

    public function test_vault_binding_throws_configuration_exception_for_invalid_class(): void
    {
        config(['packages.skillcraft-credential-vault.general.services.vault' => \stdClass::class]);

        app()->forgetInstance(VaultServiceInterface::class);

        $provider = new CredentialVaultServiceProvider(app());
        $provider->register();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(VaultServiceInterface::class);

        app(VaultServiceInterface::class);
    }
}
