<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Providers;

use Illuminate\Support\ServiceProvider;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Skillcraft\CredentialVault\Services\VaultService;
use Skillcraft\Support\Traits\LoadAndPublishDataTrait;

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
    use LoadAndPublishDataTrait;

    /**
     * Register package bindings in the container.
     *
     * Config is merged here so values are available to other providers that
     * run after this one.
     */
    public function register(): void
    {
        // The provider lives at src/Providers; go up two levels to reach the
        // package root where config/ and database/ directories live.
        $this->setBasePath(dirname(__DIR__, 2))
            ->setNamespace('packages/credential-vault')
            ->loadAndPublishConfigurations(['general']);

        $this->app->singleton(
            VaultEncryptionInterface::class,
            static function ($app): VaultEncryptionInterface {
                /** @var class-string<VaultEncryptionInterface> $class */
                $class = config(
                    'packages.credential-vault.general.services.encryption',
                    LaravelVaultEncryption::class,
                );

                /** @var VaultEncryptionInterface */
                return $app->make($class);
            },
        );

        $this->app->singleton(
            VaultServiceInterface::class,
            static function ($app): VaultServiceInterface {
                /** @var class-string<VaultServiceInterface> $class */
                $class = config(
                    'packages.credential-vault.general.services.vault',
                    VaultService::class,
                );

                /** @var VaultServiceInterface */
                return $app->make($class);
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
        $this->loadMigrations(publish: true);
    }
}
