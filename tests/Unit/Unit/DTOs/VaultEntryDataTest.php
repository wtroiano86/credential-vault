<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\DTOs;

use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Skillcraft\CredentialVault\DTOs\VaultEntryData;

final class VaultEntryDataTest extends TestCase
{
    public function test_stores_all_required_properties(): void
    {
        $dto = new VaultEntryData(
            key: 'api_key',
            metadata: ['service' => 'stripe'],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $this->assertSame('api_key', $dto->key);
        $this->assertSame(['service' => 'stripe'], $dto->metadata);
        $this->assertSame('2025-01-01T00:00:00.000000Z', $dto->createdAt);
        $this->assertNull($dto->rotatedAt);
        $this->assertNull($dto->expiresAt);
    }

    public function test_stores_optional_rotated_at_when_provided(): void
    {
        $dto = new VaultEntryData(
            key: 'db_pass',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
            rotatedAt: '2025-06-01T00:00:00.000000Z',
        );

        $this->assertSame('2025-06-01T00:00:00.000000Z', $dto->rotatedAt);
    }

    public function test_stores_optional_expires_at_when_provided(): void
    {
        $dto = new VaultEntryData(
            key: 'temp_token',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
            expiresAt: '2026-01-01T00:00:00.000000Z',
        );

        $this->assertSame('2026-01-01T00:00:00.000000Z', $dto->expiresAt);
    }

    public function test_stores_both_optional_fields_when_provided(): void
    {
        $dto = new VaultEntryData(
            key: 'rotated_token',
            metadata: ['env' => 'prod'],
            createdAt: '2025-01-01T00:00:00.000000Z',
            rotatedAt: '2025-06-01T00:00:00.000000Z',
            expiresAt: '2026-01-01T00:00:00.000000Z',
        );

        $this->assertSame('2025-06-01T00:00:00.000000Z', $dto->rotatedAt);
        $this->assertSame('2026-01-01T00:00:00.000000Z', $dto->expiresAt);
    }

    public function test_accepts_an_empty_metadata_array(): void
    {
        $dto = new VaultEntryData(
            key: 'key',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $this->assertSame([], $dto->metadata);
    }

    public function test_accepts_a_rich_nested_metadata_array(): void
    {
        $dto = new VaultEntryData(
            key: 'key',
            metadata: ['a' => ['b' => 'c'], 'num' => 42],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $this->assertSame(['a' => ['b' => 'c'], 'num' => 42], $dto->metadata);
    }

    public function test_json_serialize_returns_all_five_fields_with_snake_case_keys(): void
    {
        $dto = new VaultEntryData(
            key: 'api_key',
            metadata: ['env' => 'prod'],
            createdAt: '2025-01-01T00:00:00.000000Z',
            rotatedAt: '2025-06-01T00:00:00.000000Z',
            expiresAt: '2026-01-01T00:00:00.000000Z',
        );

        $this->assertSame([
            'key' => 'api_key',
            'metadata' => ['env' => 'prod'],
            'created_at' => '2025-01-01T00:00:00.000000Z',
            'rotated_at' => '2025-06-01T00:00:00.000000Z',
            'expires_at' => '2026-01-01T00:00:00.000000Z',
        ], $dto->jsonSerialize());
    }

    public function test_json_serialize_serializes_null_rotated_at_as_null(): void
    {
        $dto = new VaultEntryData(
            key: 'api_key',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $this->assertNull($dto->jsonSerialize()['rotated_at']);
    }

    public function test_json_serialize_serializes_null_expires_at_as_null(): void
    {
        $dto = new VaultEntryData(
            key: 'api_key',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $this->assertNull($dto->jsonSerialize()['expires_at']);
    }

    public function test_json_serialize_uses_snake_case_keys_for_timestamp_fields(): void
    {
        $result = (new VaultEntryData(
            key: 'x',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
            rotatedAt: 'r',
            expiresAt: 'e',
        ))->jsonSerialize();

        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('rotated_at', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayNotHasKey('createdAt', $result);
        $this->assertArrayNotHasKey('rotatedAt', $result);
        $this->assertArrayNotHasKey('expiresAt', $result);
    }

    public function test_json_serialize_produces_valid_json_via_json_encode(): void
    {
        $dto = new VaultEntryData(
            key: 'api_key',
            metadata: ['a' => 1],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $decoded = json_decode((string) json_encode($dto), true);

        $this->assertSame('api_key', $decoded['key']);
        $this->assertSame('2025-01-01T00:00:00.000000Z', $decoded['created_at']);
        $this->assertSame(['a' => 1], $decoded['metadata']);
    }

    public function test_implements_json_serializable(): void
    {
        $dto = new VaultEntryData(
            key: 'key',
            metadata: [],
            createdAt: '2025-01-01T00:00:00.000000Z',
        );

        $this->assertInstanceOf(JsonSerializable::class, $dto);
    }

    public function test_is_a_final_class(): void
    {
        $this->assertTrue((new \ReflectionClass(VaultEntryData::class))->isFinal());
    }

    public function test_is_a_readonly_class(): void
    {
        $this->assertTrue((new \ReflectionClass(VaultEntryData::class))->isReadOnly());
    }

    public function test_has_all_properties_declared_as_readonly(): void
    {
        foreach ((new \ReflectionClass(VaultEntryData::class))->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property \${$property->getName()} must be readonly");
        }
    }
}
