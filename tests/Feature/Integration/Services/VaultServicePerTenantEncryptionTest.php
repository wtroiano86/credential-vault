<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Services;

use Illuminate\Database\Eloquent\Model;
use Tests\Feature\Packages\SkillcraftCredentialVault\CredentialVaultIntegrationTestCase;
use Tests\Feature\Packages\SkillcraftCredentialVault\Support\VaultTestModels;

final class VaultServicePerTenantEncryptionTest extends CredentialVaultIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $appKey = 'base64:'.base64_encode(random_bytes(32));
        config([
            'app.key' => $appKey,
            'app.cipher' => 'AES-256-CBC',
            'packages.skillcraft-credential-vault.general.encryption.per_tenant_key' => true,
        ]);
    }

    public function test_stores_and_retrieves_value_when_per_tenant_key_enabled(): void
    {
        $owner = VaultTestModels::owner('App\\Models\\User', 'tenant-001');
        $this->service->store($owner, 'api_key', 'tenant-secret');

        $this->assertSame('tenant-secret', $this->service->retrieve($owner, 'api_key'));
    }

    public function test_per_tenant_key_isolates_secrets_so_different_owner_cannot_read(): void
    {
        $ownerA = VaultTestModels::owner('App\\Models\\User', 'tenant-A');
        $ownerB = VaultTestModels::owner('App\\Models\\User', 'tenant-B');

        $this->service->store($ownerA, 'api_key', 'secret-A');

        $this->assertNull($this->service->retrieve($ownerB, 'api_key'));
    }

    public function test_per_tenant_key_returns_null_when_owner_primary_key_is_null(): void
    {
        $owner = new class extends Model
        {
            protected $table = 'users';

            public function getMorphClass(): string
            {
                return 'App\\Models\\User';
            }

            public function getKey(): mixed
            {
                return null;
            }
        };

        $this->service->store($owner, 'api_key', 'tenant-secret');

        $this->assertSame('tenant-secret', $this->service->retrieve($owner, 'api_key'));
    }

    public function test_per_tenant_key_returns_null_when_owner_primary_key_is_non_scalar(): void
    {
        $owner = new class extends Model
        {
            protected $table = 'users';

            public function getMorphClass(): string
            {
                return 'App\\Models\\User';
            }

            public function getKey(): mixed
            {
                return ['unsupported'];
            }
        };

        $this->service->store($owner, 'api_key', 'tenant-secret');

        $this->assertSame('tenant-secret', $this->service->retrieve($owner, 'api_key'));
    }
}
