<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Contracts\VaultEntryInterface;
use Skillcraft\CredentialVault\Contracts\VaultServiceInterface;

final class ContractsLoadTest extends TestCase
{
    public function test_vault_entry_interface_is_a_valid_php_interface(): void
    {
        $this->assertTrue((new ReflectionClass(VaultEntryInterface::class))->isInterface());
    }

    public function test_vault_entry_interface_declares_exactly_five_methods(): void
    {
        $this->assertCount(5, (new ReflectionClass(VaultEntryInterface::class))->getMethods());
    }

    public function test_vault_entry_interface_declares_get_credential_key_returning_string(): void
    {
        $method = (new ReflectionClass(VaultEntryInterface::class))->getMethod('getCredentialKey');

        $this->assertSame('string', (string) $method->getReturnType());
    }

    public function test_vault_entry_interface_declares_get_metadata_returning_array(): void
    {
        $method = (new ReflectionClass(VaultEntryInterface::class))->getMethod('getMetadata');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_vault_entry_interface_declares_get_rotated_at_with_a_nullable_return_type(): void
    {
        $method = (new ReflectionClass(VaultEntryInterface::class))->getMethod('getRotatedAt');

        $this->assertTrue($method->getReturnType()->allowsNull());
    }

    public function test_vault_entry_interface_declares_get_expires_at_with_a_nullable_return_type(): void
    {
        $method = (new ReflectionClass(VaultEntryInterface::class))->getMethod('getExpiresAt');

        $this->assertTrue($method->getReturnType()->allowsNull());
    }

    public function test_vault_entry_interface_declares_is_expired_returning_bool(): void
    {
        $method = (new ReflectionClass(VaultEntryInterface::class))->getMethod('isExpired');

        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function test_vault_entry_interface_has_no_constructor_pure_interface(): void
    {
        $this->assertNull((new ReflectionClass(VaultEntryInterface::class))->getConstructor());
    }

    public function test_vault_encryption_interface_is_a_valid_php_interface(): void
    {
        $this->assertTrue((new ReflectionClass(VaultEncryptionInterface::class))->isInterface());
    }

    public function test_vault_encryption_interface_declares_exactly_two_methods(): void
    {
        $this->assertCount(2, (new ReflectionClass(VaultEncryptionInterface::class))->getMethods());
    }

    public function test_vault_encryption_interface_declares_encrypt_with_a_required_value_parameter(): void
    {
        $method = (new ReflectionClass(VaultEncryptionInterface::class))->getMethod('encrypt');
        $params = $method->getParameters();

        $this->assertSame('value', $params[0]->getName());
        $this->assertFalse($params[0]->isOptional());
    }

    public function test_vault_encryption_interface_declares_encrypt_with_an_optional_tenant_key_parameter(): void
    {
        $method = (new ReflectionClass(VaultEncryptionInterface::class))->getMethod('encrypt');
        $params = $method->getParameters();

        $this->assertSame('tenantKey', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertTrue($params[1]->allowsNull());
    }

    public function test_vault_encryption_interface_declares_encrypt_returning_string(): void
    {
        $method = (new ReflectionClass(VaultEncryptionInterface::class))->getMethod('encrypt');

        $this->assertSame('string', (string) $method->getReturnType());
    }

    public function test_vault_encryption_interface_declares_decrypt_with_a_required_encrypted_parameter(): void
    {
        $method = (new ReflectionClass(VaultEncryptionInterface::class))->getMethod('decrypt');
        $params = $method->getParameters();

        $this->assertSame('encrypted', $params[0]->getName());
        $this->assertFalse($params[0]->isOptional());
    }

    public function test_vault_encryption_interface_declares_decrypt_with_an_optional_tenant_key_parameter(): void
    {
        $method = (new ReflectionClass(VaultEncryptionInterface::class))->getMethod('decrypt');
        $params = $method->getParameters();

        $this->assertSame('tenantKey', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
    }

    public function test_vault_encryption_interface_declares_decrypt_returning_string(): void
    {
        $method = (new ReflectionClass(VaultEncryptionInterface::class))->getMethod('decrypt');

        $this->assertSame('string', (string) $method->getReturnType());
    }

    public function test_vault_service_interface_is_a_valid_php_interface(): void
    {
        $this->assertTrue((new ReflectionClass(VaultServiceInterface::class))->isInterface());
    }

    public function test_vault_service_interface_declares_exactly_six_methods(): void
    {
        $this->assertCount(6, (new ReflectionClass(VaultServiceInterface::class))->getMethods());
    }

    public function test_vault_service_interface_declares_store_method(): void
    {
        $this->assertTrue((new ReflectionClass(VaultServiceInterface::class))->hasMethod('store'));
    }

    public function test_vault_service_interface_declares_store_returning_vault_entry_interface(): void
    {
        $method = (new ReflectionClass(VaultServiceInterface::class))->getMethod('store');

        $this->assertStringContainsString('VaultEntryInterface', (string) $method->getReturnType());
    }

    public function test_vault_service_interface_declares_retrieve_returning_nullable_string(): void
    {
        $method = (new ReflectionClass(VaultServiceInterface::class))->getMethod('retrieve');

        $this->assertTrue($method->getReturnType()->allowsNull());
    }

    public function test_vault_service_interface_declares_delete_returning_void(): void
    {
        $method = (new ReflectionClass(VaultServiceInterface::class))->getMethod('delete');

        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_vault_service_interface_declares_rotate_method(): void
    {
        $this->assertTrue((new ReflectionClass(VaultServiceInterface::class))->hasMethod('rotate'));
    }

    public function test_vault_service_interface_declares_rotate_returning_vault_entry_interface(): void
    {
        $method = (new ReflectionClass(VaultServiceInterface::class))->getMethod('rotate');

        $this->assertStringContainsString('VaultEntryInterface', (string) $method->getReturnType());
    }

    public function test_vault_service_interface_declares_exists_returning_bool(): void
    {
        $method = (new ReflectionClass(VaultServiceInterface::class))->getMethod('exists');

        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function test_vault_service_interface_declares_list_keys_method(): void
    {
        $this->assertTrue((new ReflectionClass(VaultServiceInterface::class))->hasMethod('listKeys'));
    }

    public function test_vault_service_interface_declares_list_keys_returning_array(): void
    {
        $method = (new ReflectionClass(VaultServiceInterface::class))->getMethod('listKeys');

        $this->assertSame('array', (string) $method->getReturnType());
    }
}
