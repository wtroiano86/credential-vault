<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use Skillcraft\CredentialVault\Console\UpgradeEncryption;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Exceptions\ConfigurationException;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Skillcraft\CredentialVault\Services\VaultService;

/**
 * Service provider for the Skillcraft Credential Vault package.
 *
 * Wires configuration merging, migration loading, and interface-to-implementation
 * bindings so the package works out of the box via Laravel auto-discovery.
 *
 * Configuration is published under:
 *   `packages.credential-vault.general.*`
 */
final class CredentialVaultServiceProvider extends ServiceProvider
{
    private const string CONFIG_PREFIX = 'packages.credential-vault.general';

    private const string LEGACY_CONFIG_PREFIX = 'packages.skillcraft-credential-vault.general';

    /**
     * Register package bindings in the container.
     *
     * Config is merged here so values are available to other providers that
     * run after this one.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/general.php', self::CONFIG_PREFIX);

        $this->app->singleton(
            VaultEncryptionInterface::class,
            static function (Application $app): VaultEncryptionInterface {
                /** @var class-string<VaultEncryptionInterface> $class */
                $class = self::config('services.encryption', LaravelVaultEncryption::class);

                $resolved = $app->make($class);

                if (! $resolved instanceof VaultEncryptionInterface) {
                    throw new ConfigurationException(
                        "Encryption service [{$class}] must implement ".VaultEncryptionInterface::class.'.'
                    );
                }

                return $resolved;
            },
        );

        $this->app->singleton(
            VaultServiceInterface::class,
            static function (Application $app): VaultServiceInterface {
                /** @var class-string<VaultServiceInterface> $class */
                $class = self::config('services.vault', VaultService::class);

                $resolved = $app->make($class);

                if (! $resolved instanceof VaultServiceInterface) {
                    throw new ConfigurationException(
                        "Vault service [{$class}] must implement ".VaultServiceInterface::class.'.'
                    );
                }

                return $resolved;
            },
        );
    }

    /**
     * Bootstrap package services.
     *
     * Migrations are loaded here so they run after all providers have registered.
     */
    public function boot(): void
    {
        $this->commands([UpgradeEncryption::class]);

        $basePath = dirname(__DIR__, 2);

        $this->loadMigrationsFrom($basePath.'/database/migrations');

        $this->publishes([
            $basePath.'/config/general.php' => $this->app->configPath(self::CONFIG_PREFIX.'.php'),
        ], ['credential-vault-config', 'skillcraft-credential-vault-config']);

        $this->publishes([
            $basePath.'/database/migrations' => $this->app->databasePath('migrations'),
        ], ['credential-vault-migrations', 'skillcraft-credential-vault-migrations']);
    }

    private static function config(string $key, mixed $default = null): mixed
    {
        $legacyKey = self::LEGACY_CONFIG_PREFIX.'.'.$key;

        if (config()->has($legacyKey)) {
            return config($legacyKey);
        }

        return config(self::CONFIG_PREFIX.'.'.$key, $default);
    }
}
