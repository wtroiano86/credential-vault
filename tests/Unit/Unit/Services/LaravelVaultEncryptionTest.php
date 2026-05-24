<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Services;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Mockery;
use RuntimeException;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Skillcraft\CredentialVault\Services\LaravelVaultEncryption;
use Tests\Unit\Packages\SkillcraftCredentialVault\CredentialVaultTestCase;

final class LaravelVaultEncryptionTest extends CredentialVaultTestCase
{
    /**
     * Build a real Encrypter using a randomly-generated 32-byte key so tests
     * don't depend on app config.
     */
    private function makeEncrypter(): Encrypter
    {
        return new Encrypter(random_bytes(32), 'AES-256-CBC');
    }

    private function configureTenantCipher(string $cipher): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $key, 'app.cipher' => $cipher]);
    }

    public function test_encrypt_and_decrypt_plain_text_round_trip_without_tenant_key(): void
    {
        $encrypter = $this->makeEncrypter();
        $service = new LaravelVaultEncryption($encrypter);

        $encrypted = $service->encrypt('my-secret');
        $decrypted = $service->decrypt($encrypted);

        $this->assertSame('my-secret', $decrypted);
    }

    public function test_encrypt_produces_a_non_empty_ciphertext_string(): void
    {
        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('value');

        $this->assertIsString($encrypted);
        $this->assertNotSame('', $encrypted);
    }

    public function test_encrypt_produces_different_ciphertexts_for_the_same_plaintext_iv_randomisation(): void
    {
        $service = new LaravelVaultEncryption($this->makeEncrypter());

        $a = $service->encrypt('same-value');
        $b = $service->encrypt('same-value');

        $this->assertNotSame($a, $b);
    }

    public function test_decrypt_restores_exact_original_string_with_unicode(): void
    {
        $service = new LaravelVaultEncryption($this->makeEncrypter());

        $value = 'exact-string-🔑';
        $encrypted = $service->encrypt($value);

        $this->assertSame($value, $service->decrypt($encrypted));
    }

    public function test_passes_null_tenant_key_through_explicitly(): void
    {
        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('hello', null);

        $this->assertSame('hello', $service->decrypt($encrypted, null));
    }

    public function test_encrypt_wraps_generic_throwable_in_crypto_operation_exception(): void
    {
        $broken = Mockery::mock(EncrypterContract::class);
        $broken->shouldReceive('encrypt')->andThrow(new RuntimeException('boom'));

        $service = new LaravelVaultEncryption($broken);

        $this->expectException(CryptoOperationException::class);
        $this->expectExceptionMessage('Encryption failed: boom');

        $service->encrypt('val');
    }

    public function test_encrypt_rethrows_crypto_operation_exception_directly_without_wrapping(): void
    {
        $broken = Mockery::mock(EncrypterContract::class);
        $broken->shouldReceive('encrypt')->andThrow(new CryptoOperationException('direct'));

        $service = new LaravelVaultEncryption($broken);

        try {
            $service->encrypt('val');
            $this->fail('Expected CryptoOperationException');
        } catch (CryptoOperationException $ex) {
            $this->assertSame('direct', $ex->getMessage());
        }
    }

    public function test_decrypt_wraps_generic_throwable_in_crypto_operation_exception(): void
    {
        $broken = Mockery::mock(EncrypterContract::class);
        $broken->shouldReceive('decrypt')->andThrow(new RuntimeException('bad payload'));

        $service = new LaravelVaultEncryption($broken);

        $this->expectException(CryptoOperationException::class);
        $this->expectExceptionMessage('Decryption failed: bad payload');

        $service->decrypt('garbage');
    }

    public function test_decrypt_rethrows_crypto_operation_exception_directly_without_wrapping(): void
    {
        $broken = Mockery::mock(EncrypterContract::class);
        $broken->shouldReceive('decrypt')->andThrow(new CryptoOperationException('direct-decrypt'));

        $service = new LaravelVaultEncryption($broken);

        try {
            $service->decrypt('val');
            $this->fail('Expected CryptoOperationException');
        } catch (CryptoOperationException $ex) {
            $this->assertSame('direct-decrypt', $ex->getMessage());
        }
    }

    public function test_encrypt_and_decrypt_with_tenant_key_round_trip_aes_256_cbc(): void
    {
        $this->configureTenantCipher('AES-256-CBC');

        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('tenant-secret', 'tenant-abc');
        $decrypted = $service->decrypt($encrypted, 'tenant-abc');

        $this->assertSame('tenant-secret', $decrypted);
    }

    public function test_cannot_decrypt_with_a_different_tenant_key_aes_256_cbc(): void
    {
        $this->configureTenantCipher('AES-256-CBC');

        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('secret', 'tenant-a');

        $this->expectException(CryptoOperationException::class);

        $service->decrypt($encrypted, 'tenant-b');
    }

    public function test_cannot_decrypt_tenant_keyed_ciphertext_without_tenant_key_aes_256_cbc(): void
    {
        $this->configureTenantCipher('AES-256-CBC');

        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('secret', 'tenant-x');

        $this->expectException(CryptoOperationException::class);

        $service->decrypt($encrypted);
    }

    public function test_produces_different_ciphertexts_for_different_tenant_keys_aes_256_cbc(): void
    {
        $this->configureTenantCipher('AES-256-CBC');

        $service = new LaravelVaultEncryption($this->makeEncrypter());

        $a = $service->encrypt('value', 'tenant-1');
        $b = $service->encrypt('value', 'tenant-2');

        $this->assertNotSame($a, $b);
    }

    public function test_encrypt_and_decrypt_with_aes_128_cbc_tenant_key_round_trip(): void
    {
        $this->configureTenantCipher('AES-128-CBC');

        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('aes128-secret', 'tenant-128');
        $decrypted = $service->decrypt($encrypted, 'tenant-128');

        $this->assertSame('aes128-secret', $decrypted);
    }

    public function test_resolve_encrypter_throws_when_app_key_is_empty(): void
    {
        config(['app.key' => '', 'app.cipher' => 'AES-256-CBC']);

        $service = new LaravelVaultEncryption($this->makeEncrypter());

        $this->expectException(CryptoOperationException::class);
        $this->expectExceptionMessage('Application key is not configured.');

        $service->encrypt('value', 'tenant-id');
    }

    public function test_resolve_encrypter_throws_when_app_key_is_invalid_base64(): void
    {
        config(['app.key' => 'base64:!!!notvalid!!!', 'app.cipher' => 'AES-256-CBC']);

        $service = new LaravelVaultEncryption($this->makeEncrypter());

        $this->expectException(CryptoOperationException::class);
        $this->expectExceptionMessage('Application key is not valid base64.');

        $service->encrypt('value', 'tenant-id');
    }

    public function test_resolve_encrypter_uses_raw_key_bytes_when_app_key_has_no_base64_prefix(): void
    {
        $rawKey = str_repeat('x', 32);
        config(['app.key' => $rawKey, 'app.cipher' => 'AES-256-CBC']);

        $service = new LaravelVaultEncryption($this->makeEncrypter());
        $encrypted = $service->encrypt('raw-key-value', 'tenant-raw');
        $decrypted = $service->decrypt($encrypted, 'tenant-raw');

        $this->assertSame('raw-key-value', $decrypted);
    }
}
