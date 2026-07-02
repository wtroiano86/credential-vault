<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

final class VaultEntryTest extends CredentialVaultTestCase
{
    public function test_returns_the_correct_table_name(): void
    {
        $this->assertSame('scp_vault_entries', (new VaultEntry)->getTable());
    }

    public function test_get_fillable_returns_all_expected_fillable_fields_in_order(): void
    {
        $this->assertSame([
            'owner_type',
            'owner_id',
            'key_name',
            'encrypted_value',
            'metadata',
            'rotated_at',
            'expires_at',
        ], (new VaultEntry)->getFillable());
    }

    public function test_get_fillable_contains_key_name_rather_than_key(): void
    {
        $fillable = (new VaultEntry)->getFillable();

        $this->assertContains('key_name', $fillable);
        $this->assertNotContains('key', $fillable);
    }

    public function test_get_hidden_hides_encrypted_value(): void
    {
        $this->assertContains('encrypted_value', (new VaultEntry)->getHidden());
    }

    public function test_get_hidden_does_not_hide_key_name(): void
    {
        $this->assertNotContains('key_name', (new VaultEntry)->getHidden());
    }

    public function test_implements_vault_entry_interface(): void
    {
        $this->assertInstanceOf(VaultEntryInterface::class, new VaultEntry);
    }

    public function test_get_credential_key_returns_the_key_name_string_attribute(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['key_name' => 'stripe_key']);

        $this->assertSame('stripe_key', $entry->getCredentialKey());
    }

    public function test_get_credential_key_returns_empty_string_when_key_name_attribute_is_absent(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes([]);

        $this->assertSame('', $entry->getCredentialKey());
    }

    public function test_get_credential_key_returns_empty_string_when_key_name_is_null(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['key_name' => null]);

        $this->assertSame('', $entry->getCredentialKey());
    }

    public function test_get_credential_key_preserves_the_exact_string_value(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['key_name' => 'github_token']);

        $this->assertSame('github_token', $entry->getCredentialKey());
    }

    public function test_get_metadata_returns_the_decoded_array_when_metadata_is_valid_json(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['metadata' => json_encode(['env' => 'prod', 'version' => 2])]);

        $this->assertSame(['env' => 'prod', 'version' => 2], $entry->getMetadata());
    }

    public function test_get_metadata_returns_empty_array_when_metadata_attribute_is_absent(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes([]);

        $this->assertSame([], $entry->getMetadata());
    }

    public function test_get_metadata_returns_empty_array_when_metadata_is_null(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['metadata' => null]);

        $this->assertSame([], $entry->getMetadata());
    }

    public function test_get_metadata_returns_empty_array_for_an_empty_json_object(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['metadata' => '{}']);

        $this->assertSame([], $entry->getMetadata());
    }

    public function test_get_metadata_returns_empty_array_for_an_empty_json_array(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['metadata' => '[]']);

        $this->assertSame([], $entry->getMetadata());
    }

    public function test_get_rotated_at_returns_a_datetime_interface_when_rotated_at_holds_a_date_string(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['rotated_at' => '2025-01-15 10:00:00']);

        $this->assertInstanceOf(DateTimeInterface::class, $entry->getRotatedAt());
    }

    public function test_get_rotated_at_returns_null_when_rotated_at_attribute_is_absent(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes([]);

        $this->assertNull($entry->getRotatedAt());
    }

    public function test_get_rotated_at_returns_null_when_rotated_at_is_null(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['rotated_at' => null]);

        $this->assertNull($entry->getRotatedAt());
    }

    public function test_get_rotated_at_reflects_the_correct_date_from_the_attribute(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['rotated_at' => '2025-06-15 12:30:00']);

        $this->assertSame('2025-06-15', $entry->getRotatedAt()->format('Y-m-d'));
    }

    public function test_get_expires_at_returns_a_datetime_interface_when_expires_at_holds_a_date_string(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => '2030-01-01 00:00:00']);

        $this->assertInstanceOf(DateTimeInterface::class, $entry->getExpiresAt());
    }

    public function test_get_expires_at_returns_null_when_expires_at_attribute_is_absent(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes([]);

        $this->assertNull($entry->getExpiresAt());
    }

    public function test_get_expires_at_returns_null_when_expires_at_is_null(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => null]);

        $this->assertNull($entry->getExpiresAt());
    }

    public function test_get_expires_at_reflects_the_correct_date_from_the_attribute(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => '2030-12-31 23:59:59']);

        $this->assertSame('2030-12-31', $entry->getExpiresAt()->format('Y-m-d'));
    }

    public function test_is_expired_returns_false_when_no_expiry_is_set(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes([]);

        $this->assertFalse($entry->isExpired());
    }

    public function test_is_expired_returns_false_when_expiry_is_in_the_future(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => '2099-12-31 23:59:59']);

        $this->assertFalse($entry->isExpired());
    }

    public function test_is_expired_returns_true_when_expiry_is_in_the_past(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => '2000-01-01 00:00:00']);

        $this->assertTrue($entry->isExpired());
    }

    public function test_is_expired_returns_true_when_expiry_timestamp_equals_exactly_now_boundary(): void
    {
        $past = Carbon::now()->subSeconds(2)->format('Y-m-d H:i:s');

        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => $past]);

        $this->assertTrue($entry->isExpired());
    }

    public function test_is_expired_returns_false_when_expires_at_is_null(): void
    {
        $entry = new VaultEntry;
        $entry->setRawAttributes(['expires_at' => null]);

        $this->assertFalse($entry->isExpired());
    }
}
