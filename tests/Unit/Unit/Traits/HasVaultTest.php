<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mockery;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Tests\Feature\Packages\SkillcraftCredentialVault\Support\VaultTestModels;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

final class HasVaultTest extends CredentialVaultTestCase
{
    private VaultServiceInterface $mockService;

    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockService = Mockery::mock(VaultServiceInterface::class);
        $this->app->instance(VaultServiceInterface::class, $this->mockService);
        $this->model = VaultTestModels::hasVaultModel();
    }

    public function test_vault_entries_returns_a_morph_many_relationship(): void
    {
        $this->assertInstanceOf(MorphMany::class, $this->model->vaultEntries());
    }

    public function test_vault_entries_morphs_to_vault_entry(): void
    {
        $this->assertInstanceOf(VaultEntry::class, $this->model->vaultEntries()->getRelated());
    }

    public function test_store_credential_delegates_to_vault_service_store(): void
    {
        $entry = Mockery::mock(VaultEntryInterface::class);

        $this->mockService
            ->shouldReceive('store')
            ->once()
            ->with($this->model, 'api_key', 'secret', [])
            ->andReturn($entry);

        $this->assertSame($entry, $this->model->storeCredential('api_key', 'secret'));
    }

    public function test_store_credential_passes_metadata_through_to_the_service(): void
    {
        $this->expectNotToPerformAssertions();

        $entry = Mockery::mock(VaultEntryInterface::class);

        $this->mockService
            ->shouldReceive('store')
            ->once()
            ->with($this->model, 'db_pass', 'pw', ['env' => 'prod'])
            ->andReturn($entry);

        $this->model->storeCredential('db_pass', 'pw', ['env' => 'prod']);
    }

    public function test_retrieve_credential_delegates_to_vault_service_retrieve(): void
    {
        $this->mockService
            ->shouldReceive('retrieve')
            ->once()
            ->with($this->model, 'api_key')
            ->andReturn('my-secret');

        $this->assertSame('my-secret', $this->model->retrieveCredential('api_key'));
    }

    public function test_retrieve_credential_returns_null_when_the_service_returns_null(): void
    {
        $this->mockService
            ->shouldReceive('retrieve')
            ->once()
            ->andReturn(null);

        $this->assertNull($this->model->retrieveCredential('missing_key'));
    }

    public function test_delete_credential_delegates_to_vault_service_delete(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mockService
            ->shouldReceive('delete')
            ->once()
            ->with($this->model, 'api_key');

        $this->model->deleteCredential('api_key');
    }

    public function test_rotate_credential_delegates_to_vault_service_rotate(): void
    {
        $entry = Mockery::mock(VaultEntryInterface::class);

        $this->mockService
            ->shouldReceive('rotate')
            ->once()
            ->with($this->model, 'jwt_secret', 'new-value')
            ->andReturn($entry);

        $this->assertSame($entry, $this->model->rotateCredential('jwt_secret', 'new-value'));
    }

    public function test_has_credential_returns_true_when_the_service_reports_the_key_exists(): void
    {
        $this->mockService
            ->shouldReceive('exists')
            ->once()
            ->with($this->model, 'api_key')
            ->andReturn(true);

        $this->assertTrue($this->model->hasCredential('api_key'));
    }

    public function test_has_credential_returns_false_when_the_service_reports_the_key_does_not_exist(): void
    {
        $this->mockService
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->assertFalse($this->model->hasCredential('missing_key'));
    }

    public function test_list_credential_keys_delegates_to_vault_service_list_keys(): void
    {
        $keys = [
            ['key' => 'api_key', 'metadata' => [], 'created_at' => '2026-01-01T00:00:00+00:00', 'rotated_at' => null],
            ['key' => 'db_pass', 'metadata' => [], 'created_at' => '2026-01-02T00:00:00+00:00', 'rotated_at' => null],
        ];

        $this->mockService
            ->shouldReceive('listKeys')
            ->once()
            ->with($this->model)
            ->andReturn($keys);

        $this->assertSame($keys, $this->model->listCredentialKeys());
    }

    public function test_list_credential_keys_returns_an_empty_array_when_no_keys_exist(): void
    {
        $this->mockService
            ->shouldReceive('listKeys')
            ->once()
            ->andReturn([]);

        $this->assertSame([], $this->model->listCredentialKeys());
    }
}
