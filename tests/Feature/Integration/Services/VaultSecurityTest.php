<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Services;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use SensitiveParameterValue;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Exceptions\ConfigurationException;
use Skillcraft\CredentialVault\Exceptions\CredentialPersistenceException;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Skillcraft\CredentialVault\Exceptions\InvalidCredentialKeyException;
use Skillcraft\CredentialVault\Exceptions\InvalidVaultOwnerException;
use Skillcraft\CredentialVault\Facades\Vault;
use Skillcraft\CredentialVault\Models\VaultEntry;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Skillcraft\CredentialVault\Traits\HasVault;
use Tests\Feature\Packages\SkillcraftCredentialVault\CredentialVaultIntegrationTestCase;

final class VaultSecurityTest extends CredentialVaultIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['security_owners', 'security_other_owners'] as $table) {
            Schema::create($table, static fn (Blueprint $table) => $table->id());
        }

        VetoVaultEntry::$veto = '';
        WakeupPayload::$wokeUp = false;
        app()->instance(VaultEncryptionInterface::class, $this->encryption);
        app()->instance(VaultServiceInterface::class, $this->service);
        Vault::clearResolvedInstances();
    }

    public static function ownerOperations(): array
    {
        return [['store'], ['retrieve'], ['rotate'], ['delete'], ['exists'], ['listKeys']];
    }

    #[DataProvider('ownerOperations')]
    public function test_unsaved_owners_are_rejected_by_every_operation(string $method): void
    {
        $this->expectException(InvalidVaultOwnerException::class);
        $this->service->$method(new SecurityOwner, 'api_key', 'value');
    }

    public static function invalidIds(): array
    {
        return [[null], [''], [false], [true], [1.5], [[]]];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_owner_ids_are_rejected_even_if_exists_is_set(mixed $id): void
    {
        $owner = new class extends SecurityOwner
        {
            public function getKey(): mixed
            {
                return $this->getAttributes()['id'];
            }
        };
        $owner->exists = true;
        $owner->setRawAttributes(['id' => $id]);
        $this->expectException(InvalidVaultOwnerException::class);
        $this->service->store($owner, 'api_key', 'value');
    }

    #[DataProvider('ownerOperations')]
    public function test_cross_connection_owners_are_rejected(string $method): void
    {
        config(['database.connections.other' => config('database.connections.testing')]);
        Schema::connection('other')->create('security_owners', static fn (Blueprint $table) => $table->id());
        $owner = SecurityOwner::on('other')->create();
        $this->expectException(InvalidVaultOwnerException::class);
        $this->service->$method($owner, 'api_key', 'value');
    }

    public static function substitutionCases(): array
    {
        return [[false, 'type'], [true, 'type'], [false, 'id'], [true, 'id'], [false, 'key'], [true, 'key']];
    }

    #[DataProvider('substitutionCases')]
    public function test_ciphertext_substitution_is_rejected(bool $derived, string $change): void
    {
        config(['packages.credential-vault.general.encryption.per_tenant_key' => $derived]);
        $first = SecurityOwner::create();
        $second = match ($change) {
            'type' => OtherSecurityOwner::create(),
            'id' => SecurityOwner::create(),
            default => $first,
        };
        $key = $change === 'key' ? 'other_key' : 'api_key';
        $victim = $this->service->store($first, 'api_key', 'victim-secret');
        $recipient = $this->service->store($second, $key, 'recipient-secret');
        $recipient->update(['encrypted_value' => $victim->getAttribute('encrypted_value')]);

        $this->expectException(CryptoOperationException::class);
        $this->service->retrieve($second, $key);
    }

    public function test_changing_database_context_rejects_ciphertext(): void
    {
        $owner = SecurityOwner::create();
        $this->service->store($owner, 'api_key', 'value');
        $connection = DB::connection();
        $original = $connection->getDatabaseName();
        $connection->setDatabaseName('different-database');

        try {
            $this->expectException(CryptoOperationException::class);
            $this->service->retrieve($owner, 'api_key');
        } finally {
            $connection->setDatabaseName($original);
        }
    }

    public function test_stripping_outer_version_cannot_enable_legacy_fallback(): void
    {
        config(['packages.credential-vault.general.encryption.allow_legacy' => true]);
        $owner = SecurityOwner::create();
        $entry = $this->service->store($owner, 'api_key', 'secret');
        $entry->update(['encrypted_value' => substr($entry->getAttribute('encrypted_value'), 5)]);
        $this->expectException(CryptoOperationException::class);
        $this->service->retrieve($owner, 'api_key');
    }

    public function test_stripping_driver_version_cannot_enable_legacy_fallback(): void
    {
        $encrypted = $this->encryption->encrypt('secret');
        $this->expectException(CryptoOperationException::class);
        $this->encryption->decrypt(substr($encrypted, 5));
    }

    public function test_legacy_object_payload_never_invokes_wakeup(): void
    {
        $payload = $this->encrypter->encrypt(new WakeupPayload);

        try {
            $this->encryption->decrypt($payload);
            $this->fail('Object payload must be rejected.');
        } catch (CryptoOperationException) {
            $this->assertFalse(WakeupPayload::$wokeUp);
        }
    }

    public static function serializedPayloads(): array
    {
        return [['a:0:{}'], ['s:2:"x";'], ['s:01:"x";'], ['s:1:"x";trailing'], ['s:1:"x";'."\n"], ['N;']];
    }

    #[DataProvider('serializedPayloads')]
    public function test_legacy_parser_rejects_noncanonical_strings(string $payload): void
    {
        $this->expectException(CryptoOperationException::class);
        $this->encryption->decrypt($this->encrypter->encrypt($payload, false));
    }

    public static function secretValues(): array
    {
        return [[''], ["\0\xff\n\";binary🔑"], [str_repeat('x', 5000)]];
    }

    #[DataProvider('secretValues')]
    public function test_binary_and_empty_strings_round_trip_in_new_and_legacy_formats(string $value): void
    {
        $owner = SecurityOwner::create();
        $this->service->store($owner, 'api_key', $value);
        $this->assertSame($value, $this->service->retrieve($owner, 'api_key'));
        $this->assertSame($value, $this->encryption->decrypt($this->encrypter->encrypt($value)));
    }

    public static function secretApis(): array
    {
        return [['service', 'store'], ['service', 'rotate'], ['trait', 'store'], ['trait', 'rotate'], ['facade', 'store'], ['facade', 'rotate']];
    }

    #[DataProvider('secretApis')]
    public function test_secret_arguments_are_redacted_when_exception_arguments_are_enabled(string $api, string $method): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $owner = SecurityOwner::create();

        try {
            match ($api) {
                'trait' => $owner->{$method.'Credential'}('INVALID', 'secret-in-trace'),
                'facade' => Vault::$method($owner, 'INVALID', 'secret-in-trace'),
                default => $this->service->$method($owner, 'INVALID', 'secret-in-trace'),
            };
            $this->fail('Invalid key must be rejected.');
        } catch (InvalidCredentialKeyException $exception) {
            $redacted = 0;
            foreach ($exception->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    $this->assertNotSame('secret-in-trace', $argument);
                    if ($argument instanceof SensitiveParameterValue) {
                        $redacted++;
                    } elseif (is_array($argument)) {
                        $this->assertNotContains('secret-in-trace', $argument);
                    }
                }
            }
            $this->assertGreaterThan(0, $redacted);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function rejectedKeys(): array
    {
        return [["api_key\n"], [str_repeat('a', 100)."\n"], [str_repeat('a', 101)]];
    }

    #[DataProvider('rejectedKeys')]
    public function test_default_key_policy_rejects_trailing_newlines_and_overlong_names(string $key): void
    {
        $this->expectException(InvalidCredentialKeyException::class);
        $this->service->store(SecurityOwner::create(), $key, 'value');
    }

    public function test_store_after_delete_restores_row_with_new_value_and_clears_old_lifecycle(): void
    {
        $owner = SecurityOwner::create();
        $entry = $this->service->store($owner, 'api_key', 'old', ['old' => true]);
        $entry->update(['expires_at' => now()->subDay(), 'rotated_at' => now()->subHour()]);
        $this->service->delete($owner, 'api_key');
        $restored = $this->service->store($owner, 'api_key', 'new', ['new' => true]);

        $this->assertSame($entry->getKey(), $restored->getKey());
        $this->assertSame(1, VaultEntry::withTrashed()->count());
        $this->assertNull($restored->getExpiresAt());
        $this->assertNull($restored->getRotatedAt());
        $this->assertSame(['new' => true], $restored->getMetadata());
        $this->assertSame('new', $this->service->retrieve($owner, 'api_key'));
    }

    public static function vetoOperations(): array
    {
        return [['create', 'saving'], ['store', 'saving'], ['rotate', 'saving'], ['restore', 'restoring'], ['restore', 'saving'], ['delete', 'deleting']];
    }

    #[DataProvider('vetoOperations')]
    public function test_observer_vetoes_throw_and_leave_database_and_events_unchanged(string $operation, string $event): void
    {
        config(['packages.credential-vault.general.model' => VetoVaultEntry::class]);
        $owner = SecurityOwner::create();
        if ($operation !== 'create') {
            $this->service->store($owner, 'api_key', 'original');
        }
        if ($operation === 'restore') {
            $this->service->delete($owner, 'api_key');
        }
        $before = DB::table('scp_vault_entries')->get()->toJson();
        $events = [];
        foreach ([CredentialStored::class, CredentialRotated::class, CredentialDeleted::class] as $type) {
            Event::listen($type, function ($event) use (&$events): void {
                $events[] = $event;
            });
        }
        VetoVaultEntry::$veto = $event;

        try {
            $method = in_array($operation, ['create', 'restore'], true) ? 'store' : $operation;
            $this->service->$method($owner, 'api_key', 'replacement');
            $this->fail('Vetoed mutation must throw.');
        } catch (CredentialPersistenceException) {
            $this->assertSame($before, DB::table('scp_vault_entries')->get()->toJson());
            $this->assertSame([], $events);
        }
    }

    public static function transactionOperations(): array
    {
        return [['store', true], ['store', false], ['rotate', true], ['rotate', false], ['delete', true], ['delete', false]];
    }

    #[DataProvider('transactionOperations')]
    public function test_mutation_events_follow_outer_transaction_outcome(string $operation, bool $commit): void
    {
        $owner = SecurityOwner::create();
        if ($operation !== 'store') {
            $this->service->store($owner, 'api_key', 'original');
        }
        $events = [];
        foreach ([CredentialStored::class, CredentialRotated::class, CredentialDeleted::class] as $type) {
            Event::listen($type, function ($event) use (&$events): void {
                $events[] = $event;
            });
        }
        DB::beginTransaction();
        $this->service->$operation($owner, 'api_key', 'replacement');
        $this->assertSame([], $events);
        $commit ? DB::commit() : DB::rollBack();
        $this->assertCount($commit ? 1 : 0, $events);
        $expected = $commit ? ($operation === 'delete' ? null : 'replacement') : ($operation === 'store' ? null : 'original');
        $this->assertSame($expected, $this->service->retrieve($owner, 'api_key'));
    }

    public function test_missing_delete_does_not_dispatch_an_event(): void
    {
        Event::fake([CredentialDeleted::class]);
        app(VaultServiceInterface::class)->delete(SecurityOwner::create(), 'api_key');
        Event::assertNotDispatched(CredentialDeleted::class);
    }

    public function test_legacy_service_reads_are_rejected_by_default(): void
    {
        $owner = SecurityOwner::create();
        $entry = $this->service->store($owner, 'api_key', 'new-format');
        $entry->update(['encrypted_value' => $this->encrypter->encrypt('legacy')]);
        $this->expectException(CryptoOperationException::class);
        $this->service->retrieve($owner, 'api_key');
    }

    public static function encryptionModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('encryptionModes')]
    public function test_legacy_upgrade_is_resumable_and_preserves_metadata_and_lifecycle(bool $derived): void
    {
        config(['packages.credential-vault.general.encryption.per_tenant_key' => $derived]);
        $owner = SecurityOwner::create();
        $legacyEncrypter = $derived
            ? new Encrypter(hash_hkdf('sha256', base64_decode(substr(config('app.key'), 7)), 32, 'skillcraft-credential-vault', (string) $owner->getKey()), 'AES-256-CBC')
            : $this->encrypter;
        $legacy = $legacyEncrypter->encrypt("legacy\0\xff");
        $entry = $this->service->store($owner, 'api_key', 'new', ['env' => 'test']);
        $entry->update(['encrypted_value' => $legacy]);
        config(['packages.credential-vault.general.encryption.allow_legacy' => true]);
        $this->assertSame("legacy\0\xff", $this->service->retrieve($owner, 'api_key'));
        config(['packages.credential-vault.general.encryption.allow_legacy' => false]);
        $entry->update(['expires_at' => now()->subHour(), 'rotated_at' => now()->subDay()]);
        $this->service->delete($owner, 'api_key');
        $before = $entry->fresh()->getRawOriginal();

        $this->artisan('credential-vault:upgrade-encryption --dry-run')->expectsOutput('Would upgrade: 1. Failed: 0.')->assertSuccessful();
        $this->assertSame($legacy, $entry->fresh()->getAttribute('encrypted_value'));
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 1. Failed: 0.')->assertSuccessful();
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 0.')->assertSuccessful();
        $after = $entry->fresh()->getRawOriginal();
        unset($before['encrypted_value'], $after['encrypted_value']);
        $this->assertSame($before, $after);
        $entry->refresh()->restore();
        config(['packages.credential-vault.general.expired.return_null' => false]);
        $this->assertSame("legacy\0\xff", $this->service->retrieve($owner, 'api_key'));
    }

    public function test_upgrade_reports_failed_rows_and_does_not_replace_them(): void
    {
        $entry = $this->service->store(SecurityOwner::create(), 'api_key', 'value');
        $entry->update(['encrypted_value' => $this->encrypter->encrypt(new WakeupPayload)]);
        $before = $entry->fresh()->getRawOriginal();
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 1.')->assertFailed();
        $this->assertFalse(WakeupPayload::$wokeUp);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_upgrade_reports_driver_failures_without_exposing_sensitive_details(bool $dryRun): void
    {
        $entry = $this->service->store(SecurityOwner::create(), 'api_key', 'private-credential-value');
        $before = $entry->fresh()->getRawOriginal();
        $this->mock(VaultEncryptionInterface::class)
            ->shouldReceive('decrypt')->once()
            ->andThrow(new RuntimeException('private-driver-secret'));

        $this->artisan('credential-vault:upgrade-encryption', ['--dry-run' => $dryRun])
            ->expectsOutput('A credential could not be upgraded.')
            ->expectsOutput(($dryRun ? 'Would upgrade: ' : 'Upgraded: ').'0. Failed: 1.')
            ->doesntExpectOutputToContain('private-driver-secret')
            ->doesntExpectOutputToContain('private-credential-value')
            ->doesntExpectOutputToContain($entry->getAttribute('encrypted_value'))
            ->assertFailed();

        $this->assertSame($before, $entry->fresh()->getRawOriginal());
    }

    public static function badPatterns(): array
    {
        return [[null], [['/[/']], [[42]]];
    }

    #[DataProvider('badPatterns')]
    public function test_invalid_key_pattern_configuration_fails_closed(mixed $patterns): void
    {
        config(['packages.credential-vault.general.allowed_key_patterns' => $patterns]);
        $this->expectException(ConfigurationException::class);
        $this->service->store(SecurityOwner::create(), 'api_key', 'secret');
    }

    public function test_authenticated_payload_with_false_driver_marker_is_rejected(): void
    {
        $payload = 'scv1:'.$this->encrypter->encrypt('unmarked-string', false);
        $this->expectException(CryptoOperationException::class);
        $this->encryption->decrypt($payload);
    }

    public function test_encryption_failure_redacts_secret_arguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        config(['app.key' => '']);

        try {
            $this->encryption->encrypt('secret-in-trace', 'tenant');
            $this->fail('Missing application key must throw.');
        } catch (CryptoOperationException $exception) {
            $frame = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['function'] ?? '') === 'encrypt'))[0];
            $this->assertInstanceOf(SensitiveParameterValue::class, $frame['args'][0]);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_upgrade_observer_veto_leaves_row_unchanged_and_can_be_retried(): void
    {
        config(['packages.credential-vault.general.model' => VetoVaultEntry::class]);
        $entry = $this->service->store(SecurityOwner::create(), 'api_key', 'secret');
        $entry->update(['encrypted_value' => $this->encrypter->encrypt('legacy')]);
        $before = $entry->fresh()->getRawOriginal();
        VetoVaultEntry::$veto = 'saving';
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 1.')->assertFailed();
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        VetoVaultEntry::$veto = '';
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 1. Failed: 0.')->assertSuccessful();
    }

    public function test_upgrade_processes_multiple_batches_and_keeps_failed_rows_unchanged(): void
    {
        $owner = SecurityOwner::create();
        $entries = [];
        for ($i = 0; $i < 101; $i++) {
            $entry = $this->service->store($owner, 'api_key_'.$i, 'secret');
            $entry->update(['encrypted_value' => $this->encrypter->encrypt('legacy-'.$i)]);
            $entries[] = $entry;
        }
        $broken = $this->service->store($owner, 'broken_key', 'secret');
        $broken->update(['encrypted_value' => 'invalid-ciphertext']);
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 101. Failed: 1.')->assertFailed();
        foreach ($entries as $i => $entry) {
            $this->assertSame('legacy-'.$i, $this->service->retrieve($owner, $entry->getCredentialKey()));
        }
        $this->assertSame('invalid-ciphertext', $broken->fresh()->getAttribute('encrypted_value'));
        $this->artisan('credential-vault:upgrade-encryption')->expectsOutput('Upgraded: 0. Failed: 1.')->assertFailed();
    }

    #[DataProvider('encryptionModes')]
    public function test_configured_vault_connection_controls_transaction_events(bool $commit): void
    {
        config(['database.connections.other' => config('database.connections.testing')]);
        $connection = DB::connection('other');
        $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'scp_vault_entries'");
        $connection->statement($schema->sql);
        Schema::connection('other')->create('security_owners', static fn (Blueprint $table) => $table->id());
        // This connection has no test wrapper transaction: use the production manager.
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        config(['packages.credential-vault.general.model' => OtherConnectionVaultEntry::class]);
        $owner = SecurityOwner::on('other')->create();
        $events = [];
        Event::listen(CredentialStored::class, function ($event) use (&$events): void {
            $events[] = $event;
        });

        $connection->beginTransaction();
        $this->service->store($owner, 'api_key', 'secret');
        $this->assertSame([], $events);
        $commit ? $connection->commit() : $connection->rollBack();
        $this->assertCount($commit ? 1 : 0, $events);
        $this->assertSame($commit ? 'secret' : null, $this->service->retrieve($owner, 'api_key'));
        $this->assertSame(0, VaultEntry::count());
    }

    public static function cipherModes(): array
    {
        return [['AES-128-CBC', 16], ['AES-256-CBC', 32], ['AES-128-GCM', 16], ['AES-256-GCM', 32]];
    }

    #[DataProvider('cipherModes')]
    public function test_supported_ciphers_round_trip_and_reject_tampering_and_wrong_keys(string $cipher, int $length): void
    {
        $root = random_bytes($length);
        config(['app.key' => 'base64:'.base64_encode($root), 'app.cipher' => $cipher]);
        $encryption = new LaravelVaultEncryption(new Encrypter($root, $cipher));

        foreach ([null, 'owner-context'] as $context) {
            $encrypted = $encryption->encrypt('secret', $context);
            $this->assertSame('secret', $encryption->decrypt($encrypted, $context));
            $payload = json_decode(base64_decode(substr($encrypted, 5)), true, flags: JSON_THROW_ON_ERROR);
            $payload['value'][0] = $payload['value'][0] === 'A' ? 'B' : 'A';
            $tampered = 'scv1:'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            foreach ([[$tampered, $context], [$encrypted, 'wrong-context']] as [$input, $scope]) {
                try {
                    $encryption->decrypt($input, $scope);
                    $this->fail('Invalid payload must be rejected.');
                } catch (CryptoOperationException $exception) {
                    $this->assertNotSame('', $exception->getMessage());
                }
            }
            $wrongKey = random_bytes($length);
            config(['app.key' => 'base64:'.base64_encode($wrongKey)]);
            try {
                (new LaravelVaultEncryption(new Encrypter($wrongKey, $cipher)))->decrypt($encrypted, $context);
                $this->fail('Wrong key must be rejected.');
            } catch (CryptoOperationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            config(['app.key' => 'base64:'.base64_encode($root)]);
        }
    }
}

class SecurityOwner extends Model
{
    use HasVault;

    public $timestamps = false;

    protected $table = 'security_owners';

    protected $guarded = [];
}

class OtherSecurityOwner extends SecurityOwner
{
    protected $table = 'security_other_owners';
}

class VetoVaultEntry extends VaultEntry
{
    public static string $veto = '';

    protected static function booted(): void
    {
        foreach (['saving', 'restoring', 'deleting'] as $event) {
            static::registerModelEvent($event, static fn (): bool => self::$veto !== $event);
        }
    }
}

class WakeupPayload
{
    public static bool $wokeUp = false;

    public function __wakeup(): void
    {
        self::$wokeUp = true;
    }
}

class OtherConnectionVaultEntry extends VaultEntry
{
    protected $connection = 'other';
}
