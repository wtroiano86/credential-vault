<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Events;

use PHPUnit\Framework\TestCase;
use Skillcraft\CredentialVault\Events\CredentialAccessed;
use Skillcraft\CredentialVault\Events\CredentialDeleted;
use Skillcraft\CredentialVault\Events\CredentialRotated;
use Skillcraft\CredentialVault\Events\CredentialStored;

final class EventsTest extends TestCase
{
    public function test_credential_accessed_stores_owner_type_owner_id_and_key(): void
    {
        $event = new CredentialAccessed('App\\Models\\User', 'user-1', 'api_key');

        $this->assertSame('App\\Models\\User', $event->ownerType);
        $this->assertSame('user-1', $event->ownerId);
        $this->assertSame('api_key', $event->key);
    }

    public function test_credential_accessed_is_a_final_readonly_class(): void
    {
        $ref = new \ReflectionClass(CredentialAccessed::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_credential_accessed_has_all_three_properties_readonly(): void
    {
        foreach ((new \ReflectionClass(CredentialAccessed::class))->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property \${$property->getName()} must be readonly");
        }
    }

    public function test_credential_accessed_accepts_arbitrary_owner_type_strings(): void
    {
        $event = new CredentialAccessed('Tenant\\Models\\Organisation', 'org-99', 'db_pass');

        $this->assertSame('Tenant\\Models\\Organisation', $event->ownerType);
    }

    public function test_credential_stored_stores_owner_type_owner_id_and_key(): void
    {
        $event = new CredentialStored('App\\Models\\User', 'user-2', 'stripe_secret');

        $this->assertSame('App\\Models\\User', $event->ownerType);
        $this->assertSame('user-2', $event->ownerId);
        $this->assertSame('stripe_secret', $event->key);
    }

    public function test_credential_stored_is_a_final_readonly_class(): void
    {
        $ref = new \ReflectionClass(CredentialStored::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_credential_stored_has_all_three_properties_readonly(): void
    {
        foreach ((new \ReflectionClass(CredentialStored::class))->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property \${$property->getName()} must be readonly");
        }
    }

    public function test_credential_stored_accepts_numeric_owner_ids_as_strings(): void
    {
        $event = new CredentialStored('App\\Models\\Team', '42', 'github_token');

        $this->assertSame('42', $event->ownerId);
    }

    public function test_credential_rotated_stores_owner_type_owner_id_and_key(): void
    {
        $event = new CredentialRotated('App\\Models\\User', 'user-3', 'jwt_secret');

        $this->assertSame('App\\Models\\User', $event->ownerType);
        $this->assertSame('user-3', $event->ownerId);
        $this->assertSame('jwt_secret', $event->key);
    }

    public function test_credential_rotated_is_a_final_readonly_class(): void
    {
        $ref = new \ReflectionClass(CredentialRotated::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_credential_rotated_has_all_three_properties_readonly(): void
    {
        foreach ((new \ReflectionClass(CredentialRotated::class))->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property \${$property->getName()} must be readonly");
        }
    }

    public function test_credential_deleted_stores_owner_type_owner_id_and_key(): void
    {
        $event = new CredentialDeleted('App\\Models\\User', 'user-4', 'old_key');

        $this->assertSame('App\\Models\\User', $event->ownerType);
        $this->assertSame('user-4', $event->ownerId);
        $this->assertSame('old_key', $event->key);
    }

    public function test_credential_deleted_is_a_final_readonly_class(): void
    {
        $ref = new \ReflectionClass(CredentialDeleted::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_credential_deleted_has_all_three_properties_readonly(): void
    {
        foreach ((new \ReflectionClass(CredentialDeleted::class))->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property \${$property->getName()} must be readonly");
        }
    }

    public function test_credential_deleted_accepts_ulid_style_owner_ids(): void
    {
        $event = new CredentialDeleted('App\\Models\\User', '01JKBV123ABCDEFGHIJK56789Z', 'revoked_token');

        $this->assertSame('01JKBV123ABCDEFGHIJK56789Z', $event->ownerId);
    }
}
