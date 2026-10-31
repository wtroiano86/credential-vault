<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Tests\Feature\Packages\SkillcraftCredentialVault\CredentialVaultIntegrationTestCase;

final class VaultCiphertextUpgradeTest extends CredentialVaultIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(VaultEncryptionInterface::class, $this->encryption);
        $this->createTables();
    }

    private function createTables(): void
    {
        Schema::create('context_owners', static fn (Blueprint $table) => $table->id());
        Schema::create('context_entries', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('key_name');
            $table->text('encrypted_value');
            $table->json('metadata')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['owner_type', 'owner_id', 'key_name'], DB::connection()->getTablePrefix().'context_identity_unique');
        });
        config(['packages.credential-vault.general.table' => 'context_entries']);
    }

    /** Reproduce the v1.1.0 wire format independently of the current envelope. */
    private function v2Ciphertext(VaultEntry $entry, string $value, bool $derived): string
    {
        $context = hash('sha256', json_encode([
            $entry->getConnection()->getName(),
            $entry->getConnection()->getDatabaseName(),
            $entry->getTable(),
            $entry->getAttribute('owner_type'),
            $entry->getOwnerId(),
            $entry->getAttribute('key_name'),
        ], JSON_THROW_ON_ERROR));

        return 'scv2:'.$this->encryption->encrypt("skillcraft-credential-vault:v2\n".$context.$value, $derived ? $context : null);
    }

    public static function modes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('modes')]
    public function test_v2_reads_require_explicit_compatibility(bool $derived): void
    {
        config(['packages.credential-vault.general.encryption.per_tenant_key' => $derived]);
        $owner = ContextOwner::create();
        $entry = $this->service->store($owner, 'api_key', 'current');
        $entry->update(['encrypted_value' => $this->v2Ciphertext($entry, 'v2-secret', $derived)]);

        config(['packages.credential-vault.general.encryption.allow_legacy' => true]);
        $this->assertSame('v2-secret', $this->service->retrieve($owner, 'api_key'));
        config(['packages.credential-vault.general.encryption.allow_legacy' => false]);
        $this->expectException(CryptoOperationException::class);
        $this->service->retrieve($owner, 'api_key');
    }

    public static function modesAndPrefixes(): array
    {
        return [[false, ''], [true, ''], [false, 'tenant_a_'], [true, 'tenant_a_']];
    }

    #[DataProvider('modesAndPrefixes')]
    public function test_v2_upgrade_is_resumable_and_preserves_all_lifecycle_fields(bool $derived, string $prefix): void
    {
        config(['packages.credential-vault.general.encryption.per_tenant_key' => $derived]);
        $connection = DB::connection();

        try {
            if ($prefix !== '') {
                $connection->setTablePrefix($prefix);
                $this->createTables();
            }
            $owner = ContextOwner::create();
            $entries = [];
            $before = [];
            foreach (['active_key', 'expired_key', 'deleted_key'] as $key) {
                $entry = $this->service->store($owner, $key, 'current', ['label' => $key]);
                $entry->update([
                    'encrypted_value' => $this->v2Ciphertext($entry, "v2\0\xff".$key, $derived),
                    'rotated_at' => now()->subDay(),
                    'expires_at' => $key === 'expired_key' ? now()->subHour() : now()->addDay(),
                ]);
                if ($key === 'deleted_key') {
                    $this->service->delete($owner, $key);
                }
                $entries[] = $entry;
                $before[$key] = $entry->fresh()->getRawOriginal();
            }
            $current = $this->service->store($owner, 'current_key', 'already upgraded');
            $currentBefore = $current->fresh()->getRawOriginal();
            Event::fake([CredentialStored::class, CredentialRotated::class, CredentialDeleted::class]);

            $this->artisan('credential-vault:upgrade-encryption --dry-run')->expectsOutput('Would upgrade: 3. Failed: 0.')->assertSuccessful();
            foreach ($entries as $entry) {
                $this->assertSame($before[$entry->getCredentialKey()], $entry->fresh()->getRawOriginal());
            }
            $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 3. Failed: 0.')->assertSuccessful();
            $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 0.')->assertSuccessful();
            $this->assertSame($currentBefore, $current->fresh()->getRawOriginal());
            Event::assertNothingDispatched();

            foreach ($entries as $entry) {
                $key = $entry->getCredentialKey();
                $entry->refresh();
                $after = $entry->getRawOriginal();
                $this->assertStringStartsWith('scv3:', $after['encrypted_value']);
                unset($before[$key]['encrypted_value'], $after['encrypted_value']);
                $this->assertSame($before[$key], $after);
                if ($entry->trashed()) {
                    $entry->restore();
                }
                config(['packages.credential-vault.general.expired.return_null' => false]);
                $this->assertSame("v2\0\xff".$key, $this->service->retrieve($owner, $key));
            }
        } finally {
            $connection->setTablePrefix('');
        }
    }

    public static function prefixCases(): array
    {
        $cases = [];
        foreach ([false, true] as $derived) {
            foreach ([false, true] as $allowLegacy) {
                foreach ([false, true] as $upgrade) {
                    $cases[] = [$derived, $allowLegacy, $upgrade];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('prefixCases')]
    public function test_prefix_substitution_is_rejected_for_new_and_upgraded_ciphertext(bool $derived, bool $allowLegacy, bool $upgrade): void
    {
        config([
            'packages.credential-vault.general.encryption.per_tenant_key' => $derived,
            'packages.credential-vault.general.encryption.allow_legacy' => $allowLegacy,
        ]);
        $connection = DB::connection();

        try {
            $connection->setTablePrefix('tenant_a_');
            $this->createTables();
            $victim = ContextOwner::create();
            $entry = $this->service->store($victim, 'api_key', 'tenant-a-secret');
            if ($upgrade) {
                $entry->update(['encrypted_value' => $this->v2Ciphertext($entry, 'tenant-a-secret', $derived)]);
                $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 1. Failed: 0.')->assertSuccessful();
                $entry->refresh();
            }
            $this->assertSame('tenant-a-secret', $this->service->retrieve($victim, 'api_key'));
            $copiedCiphertext = $entry->getAttribute('encrypted_value');

            $connection->setTablePrefix('tenant_b_');
            $this->createTables();
            $other = ContextOwner::create();
            $this->assertSame($victim->getKey(), $other->getKey());
            $recipient = $this->service->store($other, 'api_key', 'tenant-b-secret');
            $this->assertSame('tenant-b-secret', $this->service->retrieve($other, 'api_key'));
            $recipient->update(['encrypted_value' => $copiedCiphertext]);

            // Upgrading must not launder a context mismatch into valid ciphertext.
            $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 1.')->assertFailed();
            $this->assertSame($copiedCiphertext, $recipient->fresh()->getAttribute('encrypted_value'));
            $this->expectException(CryptoOperationException::class);
            $this->service->retrieve($other, 'api_key');
        } finally {
            $connection->setTablePrefix('');
        }
    }

    public static function alteredPayloads(): array
    {
        $cases = [];
        foreach ([false, true] as $derived) {
            foreach (['strip_v2', 'strip_v3', 'downgrade_v3', 'v2_wrong_context'] as $change) {
                $cases[] = [$derived, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('alteredPayloads')]
    public function test_compatibility_and_upgrade_reject_downgrades_and_context_mismatches(bool $derived, string $change): void
    {
        config([
            'packages.credential-vault.general.encryption.per_tenant_key' => $derived,
            'packages.credential-vault.general.encryption.allow_legacy' => true,
        ]);
        $owner = ContextOwner::create();
        $entry = $this->service->store($owner, 'api_key', 'current-secret');
        $current = $entry->getAttribute('encrypted_value');
        $v2 = $this->v2Ciphertext($entry, 'old-secret', $derived);
        $altered = match ($change) {
            'strip_v2' => substr($v2, 5),
            'strip_v3' => substr($current, 5),
            'downgrade_v3' => 'scv2:'.substr($current, 5),
            'v2_wrong_context' => $v2,
        };
        $entry->update(['key_name' => 'other_key', 'encrypted_value' => $altered]);

        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 1.')->assertFailed();
        $this->assertSame($altered, $entry->fresh()->getAttribute('encrypted_value'));
        $this->expectException(CryptoOperationException::class);
        $this->service->retrieve($owner, 'other_key');
    }
}

final class ContextOwner extends Model
{
    public $timestamps = false;

    protected $table = 'context_owners';

    protected $guarded = [];
}
