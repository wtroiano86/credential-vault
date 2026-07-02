<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Encryption\Encrypter;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Skillcraft\CredentialVault\Services\VaultService;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

abstract class CredentialVaultIntegrationTestCase extends CredentialVaultTestCase
{
    protected Encrypter $encrypter;

    protected LaravelVaultEncryption $encryption;

    protected Dispatcher $dispatcher;

    protected VaultService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $this->encryption = new LaravelVaultEncryption($this->encrypter);
        $this->dispatcher = app(Dispatcher::class);

        config([
            'packages.credential-vault.general.audit.enabled' => false,
            'packages.credential-vault.general.encryption.per_tenant_key' => false,
            'packages.credential-vault.general.expired.return_null' => true,
            'packages.credential-vault.general.allowed_key_patterns' => ['/^[a-z][a-z0-9_]{1,98}[a-z0-9]$/'],
        ]);

        $this->service = new VaultService($this->encryption, $this->dispatcher);
    }
}
