<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Integration\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;
use Skillcraft\CredentialVault\Exceptions\CredentialNotFoundException;
use Skillcraft\CredentialVault\Exceptions\CredentialPersistenceException;
use Tests\Feature\Packages\SkillcraftCredentialVault\CredentialVaultIntegrationTestCase;

final class VaultIsolationTest extends CredentialVaultIntegrationTestCase
{
    private function createTables(string $collation): void
    {
        foreach (['isolation_owners', 'isolation_other_owners'] as $name) {
            Schema::create($name, static function (Blueprint $table): void {
                $table->string('id')->collation('BINARY')->primary();
            });
        }
        Schema::create('isolation_entries', static function (Blueprint $table) use ($collation): void {
            $table->ulid('id')->primary();
            $table->string('owner_type')->collation($collation);
            $table->string('owner_id')->collation($collation);
            $table->string('key_name')->collation($collation);
            $table->text('encrypted_value');
            $table->json('metadata')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['owner_type', 'owner_id', 'key_name']);
        });
        config([
            'packages.credential-vault.general.table' => 'isolation_entries',
            // Exercise custom key policies as well as arbitrary owner identities.
            'packages.credential-vault.general.allowed_key_patterns' => ['/\A[a-zA-Z][a-zA-Z0-9_ ]{1,99}\z/'],
            'packages.credential-vault.general.audit.enabled' => true,
        ]);
    }

    /** @return array{Model, string, Model, string} */
    private function identities(string $dimension, string $collation): array
    {
        $victim = IsolationOwner::create(['id' => 'owner-one']);
        $key = 'api_key';
        $different = static fn (string $value): string => $collation === 'NOCASE' ? strtoupper($value) : $value.' ';

        if ($dimension === 'owner_type') {
            $other = IsolationOtherOwner::create(['id' => 'owner-one']);
            $other->morphType = $different($victim->getMorphClass());
        } elseif ($dimension === 'owner_id') {
            $other = IsolationOwner::create(['id' => $different('owner-one')]);
        } else {
            $other = $victim;
            $key = $different($key);
        }

        return [$victim, 'api_key', $other, $key];
    }

    public static function identitiesAndModes(): array
    {
        $cases = [];
        foreach ([false, true] as $derived) {
            foreach (['NOCASE', 'RTRIM'] as $collation) {
                foreach (['owner_type', 'owner_id', 'key_name'] as $dimension) {
                    $cases[] = [$derived, $collation, $dimension];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('identitiesAndModes')]
    public function test_reads_require_exact_identity(bool $derived, string $collation, string $dimension): void
    {
        $this->createTables($collation);
        config(['packages.credential-vault.general.encryption.per_tenant_key' => $derived]);
        [$victim, $key, $other, $otherKey] = $this->identities($dimension, $collation);
        $this->service->store($victim, $key, 'victim-secret', ['label' => 'victim integration']);
        Event::fake([CredentialAccessed::class]);

        $this->assertNull($this->service->retrieve($other, $otherKey));
        $this->assertFalse($this->service->exists($other, $otherKey));
        if ($dimension !== 'key_name') {
            $this->assertSame([], $this->service->listKeys($other));
        }
        Event::assertNotDispatched(CredentialAccessed::class);
        $this->assertSame('victim-secret', $this->service->retrieve($victim, $key));
        $this->assertTrue($this->service->exists($victim, $key));
        $this->assertSame([$key], array_column($this->service->listKeys($victim), 'key'));
    }

    public static function mutations(): array
    {
        $cases = [];
        foreach (self::identitiesAndModes() as $case) {
            foreach (['store', 'rotate', 'delete', 'restore'] as $operation) {
                $cases[] = [...$case, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('mutations')]
    public function test_mutations_cannot_change_a_colliding_identity(bool $derived, string $collation, string $dimension, string $operation): void
    {
        $this->createTables($collation);
        config(['packages.credential-vault.general.encryption.per_tenant_key' => $derived]);
        [$victim, $key, $other, $otherKey] = $this->identities($dimension, $collation);
        $this->service->store($victim, $key, 'victim-secret', ['label' => 'victim integration']);
        if ($operation === 'restore') {
            $this->service->delete($victim, $key);
        }
        $before = DB::table('isolation_entries')->get()->toJson();
        Event::fake([CredentialStored::class, CredentialRotated::class, CredentialDeleted::class]);
        $caught = null;

        try {
            $method = $operation === 'restore' ? 'store' : $operation;
            $this->service->$method($other, $otherKey, 'replacement-secret');
        } catch (CredentialPersistenceException|CredentialNotFoundException $exception) {
            $caught = $exception;
        }

        if ($operation === 'delete') {
            $this->assertNull($caught);
        } else {
            $this->assertInstanceOf($operation === 'rotate' ? CredentialNotFoundException::class : CredentialPersistenceException::class, $caught);
        }
        $this->assertSame($before, DB::table('isolation_entries')->get()->toJson());
        Event::assertNothingDispatched();
    }

    public function test_binary_collation_allows_distinct_case_sensitive_owners_to_use_the_same_key(): void
    {
        $this->createTables('BINARY');
        $first = IsolationOwner::create(['id' => 'owner-one']);
        $second = IsolationOwner::create(['id' => 'OWNER-ONE']);
        $this->service->store($first, 'api_key', 'first-secret');
        $this->service->store($second, 'api_key', 'second-secret');
        $this->service->rotate($second, 'api_key', 'rotated-secret');
        $this->assertSame('first-secret', $this->service->retrieve($first, 'api_key'));
        $this->assertSame('rotated-secret', $this->service->retrieve($second, 'api_key'));
        $this->service->delete($second, 'api_key');
        $this->assertTrue($this->service->exists($first, 'api_key'));
        $this->assertFalse($this->service->exists($second, 'api_key'));
    }
}

class IsolationOwner extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'isolation_owners';

    protected $guarded = [];

    public string $morphType = 'isolation-owner';

    public function getMorphClass(): string
    {
        return $this->morphType;
    }
}

final class IsolationOtherOwner extends IsolationOwner
{
    protected $table = 'isolation_other_owners';
}
