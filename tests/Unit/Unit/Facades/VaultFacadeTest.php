<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Facades;

use Illuminate\Support\Facades\Facade;
use Mockery;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Facades\Vault;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

final class VaultFacadeTest extends CredentialVaultTestCase
{
    protected function tearDown(): void
    {
        Vault::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_resolves_to_the_vault_service_interface_binding(): void
    {
        $service = Mockery::mock(VaultServiceInterface::class);
        $this->app->instance(VaultServiceInterface::class, $service);

        $this->assertSame($service, Vault::getFacadeRoot());
    }

    public function test_is_a_final_class(): void
    {
        $this->assertTrue((new \ReflectionClass(Vault::class))->isFinal());
    }

    public function test_extends_illuminate_facade(): void
    {
        $this->assertSame(
            Facade::class,
            (new \ReflectionClass(Vault::class))->getParentClass()->getName(),
        );
    }
}
