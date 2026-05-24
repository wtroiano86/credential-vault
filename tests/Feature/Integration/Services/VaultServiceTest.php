<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Exceptions\CredentialNotFoundException;
use Skillcraft\CredentialVault\Exceptions\InvalidCredentialKeyException;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Skillcraft\CredentialVault\Services\VaultService;
use Tests\Feature\Packages\SkillcraftCredentialVault\CredentialVaultIntegrationTestCase;
use Tests\Feature\Packages\SkillcraftCredentialVault\Support\VaultTestModels;

final class VaultServiceTest extends CredentialVaultIntegrationTestCase
{
    public function test_store_persists_new_entry_and_returns_vault_entry_interface(): void
    {
        $owner = VaultTestModels::owner();
        $result = $this->service->store($owner, 'api_key', 'secret-value');

        $this->assertInstanceOf(VaultEntry::class, $result);
        $this->assertSame(1, VaultEntry::count());
    }

    public function test_store_stores_the_credential_key_correctly(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'stripe_key', 'sk_live_xxx');

        $this->assertSame('stripe_key', VaultEntry::first()->getCredentialKey());
    }

    public function test_store_stores_metadata_alongside_the_entry(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'db_pass', 'pw', ['env' => 'prod']);

        $this->assertSame(['env' => 'prod'], VaultEntry::first()->getMetadata());
    }

    public function test_store_updates_existing_entry_on_second_store_upsert(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v1');
        $this->service->store($owner, 'api_key', 'v2');

        $this->assertSame(1, VaultEntry::count());
        $this->assertSame('v2', $this->service->retrieve($owner, 'api_key'));
    }

    public function test_store_dispatches_credential_stored_event(): void
    {
        Event::fake([CredentialStored::class]);

        $owner = VaultTestModels::owner('App\\Models\\User', 'user-42');
        $this->service = new VaultService($this->encryption, app(Dispatcher::class));
        $this->service->store($owner, 'api_key', 'val');

        Event::assertDispatched(CredentialStored::class, function (CredentialStored $e): bool {
            return $e->ownerType === 'App\\Models\\User'
                && $e->ownerId === 'user-42'
                && $e->key === 'api_key';
        });
    }

    public function test_store_throws_invalid_credential_key_exception_for_key_not_matching_pattern(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->store($owner, 'INVALID-KEY!', 'v');
    }

    public function test_store_throws_invalid_credential_key_exception_for_key_that_is_too_short(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->store($owner, 'a', 'v');
    }

    public function test_store_does_not_expose_encrypted_value_in_returned_model(): void
    {
        $owner = VaultTestModels::owner();
        $entry = $this->service->store($owner, 'secret_key', 'plaintext');

        $this->assertArrayNotHasKey('encrypted_value', $entry->toArray());
    }

    public function test_retrieve_returns_null_when_key_does_not_exist(): void
    {
        $owner = VaultTestModels::owner();

        $this->assertNull($this->service->retrieve($owner, 'nonexistent_key'));
    }

    public function test_retrieve_returns_original_plaintext_after_store_and_retrieve(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'db_password', 'super-secret');

        $this->assertSame('super-secret', $this->service->retrieve($owner, 'db_password'));
    }

    public function test_retrieve_returns_null_for_expired_entry_when_expired_return_null_true(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'temp_token', 'tok');

        VaultEntry::where('key_name', 'temp_token')
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($this->service->retrieve($owner, 'temp_token'));
    }

    public function test_retrieve_returns_value_for_expired_entry_when_expired_return_null_false(): void
    {
        config(['packages.skillcraft-credential-vault.general.expired.return_null' => false]);

        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'temp_token', 'tok');

        VaultEntry::where('key_name', 'temp_token')
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('tok', $this->service->retrieve($owner, 'temp_token'));
    }

    public function test_retrieve_does_not_dispatch_credential_accessed_when_audit_disabled(): void
    {
        Event::fake([CredentialAccessed::class]);

        $owner = VaultTestModels::owner();
        $this->service = new VaultService($this->encryption, app(Dispatcher::class));
        $this->service->store($owner, 'api_key', 'val');
        $this->service->retrieve($owner, 'api_key');

        Event::assertNotDispatched(CredentialAccessed::class);
    }

    public function test_retrieve_dispatches_credential_accessed_when_audit_enabled(): void
    {
        config(['packages.skillcraft-credential-vault.general.audit.enabled' => true]);
        Event::fake([CredentialAccessed::class]);

        $owner = VaultTestModels::owner('App\\Models\\User', 'user-77');
        $this->service = new VaultService($this->encryption, app(Dispatcher::class));
        $this->service->store($owner, 'audited_key', 'val');
        $this->service->retrieve($owner, 'audited_key');

        Event::assertDispatched(CredentialAccessed::class, function (CredentialAccessed $e): bool {
            return $e->ownerType === 'App\\Models\\User'
                && $e->ownerId === 'user-77'
                && $e->key === 'audited_key';
        });
    }

    public function test_retrieve_isolates_credentials_between_different_owners(): void
    {
        $ownerA = VaultTestModels::owner('App\\Models\\User', 'user-a');
        $ownerB = VaultTestModels::owner('App\\Models\\User', 'user-b');

        $this->service->store($ownerA, 'shared_key', 'value-a');
        $this->service->store($ownerB, 'shared_key', 'value-b');

        $this->assertSame('value-a', $this->service->retrieve($ownerA, 'shared_key'));
        $this->assertSame('value-b', $this->service->retrieve($ownerB, 'shared_key'));
    }

    public function test_delete_removes_entry_so_retrieve_returns_null_afterwards(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');
        $this->service->delete($owner, 'api_key');

        $this->assertNull($this->service->retrieve($owner, 'api_key'));
    }

    public function test_delete_soft_deletes_entry_still_in_with_trashed(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');
        $this->service->delete($owner, 'api_key');

        $this->assertSame(0, VaultEntry::count());
        $this->assertSame(1, VaultEntry::withTrashed()->count());
    }

    public function test_delete_dispatches_credential_deleted_event(): void
    {
        Event::fake([CredentialDeleted::class]);

        $owner = VaultTestModels::owner('App\\Models\\User', 'user-del');
        $this->service = new VaultService($this->encryption, app(Dispatcher::class));
        $this->service->store($owner, 'to_delete', 'v');
        $this->service->delete($owner, 'to_delete');

        Event::assertDispatched(CredentialDeleted::class, function (CredentialDeleted $e): bool {
            return $e->key === 'to_delete' && $e->ownerId === 'user-del';
        });
    }

    public function test_delete_does_not_throw_when_deleting_non_existent_key(): void
    {
        $this->expectNotToPerformAssertions();

        $owner = VaultTestModels::owner();

        $this->service->delete($owner, 'missing_key');
    }

    public function test_rotate_replaces_stored_value_and_returns_updated_entry(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'db_pass', 'old-pass');
        $this->service->rotate($owner, 'db_pass', 'new-pass');

        $this->assertSame('new-pass', $this->service->retrieve($owner, 'db_pass'));
    }

    public function test_rotate_sets_rotated_at_on_the_entry(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'db_pass', 'old');
        $this->service->rotate($owner, 'db_pass', 'new');

        $this->assertNotNull(VaultEntry::first()->getRotatedAt());
    }

    public function test_rotate_dispatches_credential_rotated_event(): void
    {
        Event::fake([CredentialRotated::class]);

        $owner = VaultTestModels::owner('App\\Models\\User', 'user-rot');
        $this->service = new VaultService($this->encryption, app(Dispatcher::class));
        $this->service->store($owner, 'jwt_secret', 'old');
        $this->service->rotate($owner, 'jwt_secret', 'new');

        Event::assertDispatched(CredentialRotated::class, function (CredentialRotated $e): bool {
            return $e->key === 'jwt_secret' && $e->ownerId === 'user-rot';
        });
    }

    public function test_rotate_throws_credential_not_found_when_key_does_not_exist(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(CredentialNotFoundException::class);

        $this->service->rotate($owner, 'missing_key', 'value');
    }

    public function test_rotate_throws_invalid_credential_key_exception_for_invalid_key_format(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->rotate($owner, 'BAD-KEY!', 'v');
    }

    public function test_exists_returns_false_when_key_has_not_been_stored(): void
    {
        $owner = VaultTestModels::owner();

        $this->assertFalse($this->service->exists($owner, 'api_key'));
    }

    public function test_exists_returns_true_after_storing_credential(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');

        $this->assertTrue($this->service->exists($owner, 'api_key'));
    }

    public function test_exists_returns_false_after_deleting_credential(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');
        $this->service->delete($owner, 'api_key');

        $this->assertFalse($this->service->exists($owner, 'api_key'));
    }

    public function test_exists_does_not_bleed_across_owner_boundaries(): void
    {
        $ownerA = VaultTestModels::owner('App\\Models\\User', 'a');
        $ownerB = VaultTestModels::owner('App\\Models\\User', 'b');

        $this->service->store($ownerA, 'api_key', 'v');

        $this->assertFalse($this->service->exists($ownerB, 'api_key'));
    }

    public function test_list_keys_returns_empty_array_when_owner_has_no_credentials(): void
    {
        $owner = VaultTestModels::owner();

        $this->assertSame([], $this->service->listKeys($owner));
    }

    public function test_list_keys_returns_one_entry_per_stored_credential(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v1');
        $this->service->store($owner, 'db_pass', 'v2');

        $this->assertCount(2, $this->service->listKeys($owner));
    }

    public function test_list_keys_includes_key_metadata_created_at_and_rotated_at(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'stripe_key', 'val', ['env' => 'prod']);

        $list = $this->service->listKeys($owner);
        $row = $list[0];

        $this->assertArrayHasKey('key', $row);
        $this->assertArrayHasKey('metadata', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('rotated_at', $row);
        $this->assertSame('stripe_key', $row['key']);
        $this->assertSame(['env' => 'prod'], $row['metadata']);
    }

    public function test_list_keys_reflects_null_rotated_at_before_rotation(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');

        $this->assertNull($this->service->listKeys($owner)[0]['rotated_at']);
    }

    public function test_list_keys_reflects_non_null_rotated_at_after_rotation(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');
        $this->service->rotate($owner, 'api_key', 'v2');

        $this->assertNotNull($this->service->listKeys($owner)[0]['rotated_at']);
    }

    public function test_list_keys_does_not_include_deleted_credentials(): void
    {
        $owner = VaultTestModels::owner();
        $this->service->store($owner, 'api_key', 'v');
        $this->service->store($owner, 'db_pass', 'v2');
        $this->service->delete($owner, 'api_key');

        $keys = array_column($this->service->listKeys($owner), 'key');

        $this->assertNotContains('api_key', $keys);
        $this->assertContains('db_pass', $keys);
    }

    public function test_validate_key_via_store_accepts_keys_matching_default_pattern(): void
    {
        $owner = VaultTestModels::owner();

        $this->service->store($owner, 'stripe_key', 'v');

        $this->assertTrue($this->service->exists($owner, 'stripe_key'));
    }

    public function test_validate_key_via_store_rejects_keys_with_uppercase_letters(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->store($owner, 'Stripe_Key', 'v');
    }

    public function test_validate_key_via_store_rejects_keys_with_hyphens(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->store($owner, 'stripe-key', 'v');
    }

    public function test_validate_key_via_store_rejects_keys_starting_with_digit(): void
    {
        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->store($owner, '1api_key', 'v');
    }

    public function test_validate_key_via_store_when_allowed_key_patterns_empty_throws(): void
    {
        config(['packages.skillcraft-credential-vault.general.allowed_key_patterns' => []]);

        $owner = VaultTestModels::owner();

        $this->expectException(InvalidCredentialKeyException::class);

        $this->service->store($owner, 'ANY-KEY', 'v');
    }
}
