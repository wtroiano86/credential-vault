<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Tests\Feature\Packages\SkillcraftCredentialVault\CredentialVaultIntegrationTestCase;
use Tests\Feature\Packages\SkillcraftCredentialVault\Support\VaultTestModels;

final class VaultEntryPersistenceTest extends CredentialVaultIntegrationTestCase
{
    public function test_creation_persists_a_vault_entry_with_minimal_attributes(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes());

        $this->assertTrue($entry->exists);
        $this->assertSame(1, VaultEntry::count());
    }

    public function test_creation_persists_a_vault_entry_with_all_nullable_fields_populated(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes([
            'metadata' => ['env' => 'prod'],
            'rotated_at' => '2025-01-01 00:00:00',
            'expires_at' => '2026-01-01 00:00:00',
        ]));

        $this->assertTrue($entry->exists);
    }

    public function test_creation_assigns_a_26_character_ulid_as_primary_key(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes());

        $this->assertIsString($entry->id);
        $this->assertSame(26, strlen($entry->id));
    }

    public function test_get_credential_key_returns_key_name_not_the_primary_key_ulid(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes(['key_name' => 'stripe_secret']));

        $this->assertSame('stripe_secret', $entry->getCredentialKey());
        $this->assertNotSame('stripe_secret', $entry->getKey());
    }

    public function test_attribute_casting_casts_metadata_json_to_an_array_on_retrieval(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes(['metadata' => ['service' => 'stripe', 'tier' => 2]]));

        $this->assertSame(['service' => 'stripe', 'tier' => 2], VaultEntry::first()->getMetadata());
    }

    public function test_attribute_casting_returns_an_empty_array_for_null_metadata(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes(['metadata' => null]));

        $this->assertSame([], VaultEntry::first()->getMetadata());
    }

    public function test_attribute_casting_returns_an_empty_array_for_empty_metadata(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes(['metadata' => []]));

        $this->assertSame([], VaultEntry::first()->getMetadata());
    }

    public function test_attribute_casting_casts_rotated_at_to_a_carbon_instance(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes(['rotated_at' => '2025-06-15 12:00:00']));

        $rotatedAt = VaultEntry::first()->getRotatedAt();

        $this->assertInstanceOf(DateTimeInterface::class, $rotatedAt);
        $this->assertSame('2025-06-15', $rotatedAt->format('Y-m-d'));
    }

    public function test_attribute_casting_returns_null_for_unset_rotated_at(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes());

        $this->assertNull(VaultEntry::first()->getRotatedAt());
    }

    public function test_attribute_casting_casts_expires_at_to_a_carbon_instance(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes(['expires_at' => '2026-12-31 23:59:59']));

        $expiresAt = VaultEntry::first()->getExpiresAt();

        $this->assertInstanceOf(DateTimeInterface::class, $expiresAt);
        $this->assertSame('2026-12-31', $expiresAt->format('Y-m-d'));
    }

    public function test_attribute_casting_returns_null_for_unset_expires_at(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes());

        $this->assertNull(VaultEntry::first()->getExpiresAt());
    }

    public function test_serialisation_safety_excludes_encrypted_value_from_to_array(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes(['encrypted_value' => 'super-secret']));

        $this->assertArrayNotHasKey('encrypted_value', $entry->toArray());
    }

    public function test_serialisation_safety_excludes_encrypted_value_from_to_json(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes(['encrypted_value' => 'super-secret']));

        $this->assertArrayNotHasKey('encrypted_value', json_decode($entry->toJson(), true));
    }

    public function test_serialisation_safety_includes_key_name_in_to_array(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes(['key_name' => 'github_token']));

        $this->assertArrayHasKey('key_name', $entry->toArray());
    }

    public function test_soft_deletes_soft_deletes_an_entry_excludes_from_default_queries(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes());
        $entry->delete();

        $this->assertSame(0, VaultEntry::count());
        $this->assertSame(1, VaultEntry::withTrashed()->count());
    }

    public function test_soft_deletes_sets_deleted_at_on_soft_delete(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes());
        $entry->delete();

        $trashed = VaultEntry::withTrashed()->first();

        $this->assertNotNull($trashed->deleted_at);
    }

    public function test_soft_deletes_restores_a_soft_deleted_entry(): void
    {
        $entry = VaultEntry::create(VaultTestModels::entryAttributes());
        $entry->delete();
        $entry->restore();

        $this->assertSame(1, VaultEntry::count());
    }

    public function test_relationships_exposes_polymorphic_owner_morph_to_relationship(): void
    {
        $entry = new VaultEntry;

        $this->assertInstanceOf(MorphTo::class, $entry->owner());
    }

    public function test_is_expired_with_persisted_data_returns_true_for_past_expiry(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes([
            'expires_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]));

        $this->assertTrue(VaultEntry::first()->isExpired());
    }

    public function test_is_expired_with_persisted_data_returns_false_for_future_expiry(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes([
            'expires_at' => Carbon::now()->addYear()->toDateTimeString(),
        ]));

        $this->assertFalse(VaultEntry::first()->isExpired());
    }

    public function test_is_expired_with_persisted_data_returns_false_when_no_expiry(): void
    {
        VaultEntry::create(VaultTestModels::entryAttributes());

        $this->assertFalse(VaultEntry::first()->isExpired());
    }
}
