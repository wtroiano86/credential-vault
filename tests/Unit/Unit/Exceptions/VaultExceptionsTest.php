<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault\Unit\Exceptions;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Skillcraft\CredentialVault\Exceptions\ConfigurationException;
use Skillcraft\CredentialVault\Exceptions\CredentialNotFoundException;
use Skillcraft\CredentialVault\Exceptions\CryptoOperationException;
use Skillcraft\CredentialVault\Exceptions\InvalidCredentialKeyException;

final class VaultExceptionsTest extends TestCase
{
    public function test_configuration_exception_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new ConfigurationException);
    }

    public function test_configuration_exception_is_a_final_class(): void
    {
        $this->assertTrue((new ReflectionClass(ConfigurationException::class))->isFinal());
    }

    public function test_configuration_exception_is_throwable_and_catchable_as_its_own_type(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Bad config');

        throw new ConfigurationException('Bad config');
    }

    public function test_configuration_exception_is_catchable_as_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);

        throw new ConfigurationException('Bad config');
    }

    public function test_configuration_exception_stores_the_message_correctly(): void
    {
        $this->assertSame('Key missing', (new ConfigurationException('Key missing'))->getMessage());
    }

    public function test_configuration_exception_accepts_a_custom_error_code(): void
    {
        $this->assertSame(500, (new ConfigurationException('Error', 500))->getCode());
    }

    public function test_configuration_exception_accepts_a_previous_exception(): void
    {
        $previous = new RuntimeException('Root');
        $exception = new ConfigurationException('Wrapped', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_configuration_exception_defaults_to_an_empty_message(): void
    {
        $this->assertSame('', (new ConfigurationException)->getMessage());
    }

    public function test_credential_not_found_exception_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new CredentialNotFoundException);
    }

    public function test_credential_not_found_exception_is_a_final_class(): void
    {
        $this->assertTrue((new ReflectionClass(CredentialNotFoundException::class))->isFinal());
    }

    public function test_credential_not_found_exception_is_throwable_and_catchable_as_its_own_type(): void
    {
        $this->expectException(CredentialNotFoundException::class);
        $this->expectExceptionMessage('api_key not found');

        throw new CredentialNotFoundException('api_key not found');
    }

    public function test_credential_not_found_exception_is_catchable_as_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);

        throw new CredentialNotFoundException('not found');
    }

    public function test_credential_not_found_exception_stores_the_message_correctly(): void
    {
        $this->assertSame(
            'stripe_key not found',
            (new CredentialNotFoundException('stripe_key not found'))->getMessage()
        );
    }

    public function test_credential_not_found_exception_accepts_a_custom_error_code(): void
    {
        $this->assertSame(404, (new CredentialNotFoundException('missing', 404))->getCode());
    }

    public function test_credential_not_found_exception_accepts_a_previous_exception(): void
    {
        $previous = new RuntimeException('Cause');
        $exception = new CredentialNotFoundException('Wrapped', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_crypto_operation_exception_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new CryptoOperationException);
    }

    public function test_crypto_operation_exception_is_a_final_class(): void
    {
        $this->assertTrue((new ReflectionClass(CryptoOperationException::class))->isFinal());
    }

    public function test_crypto_operation_exception_is_throwable_and_catchable_as_its_own_type(): void
    {
        $this->expectException(CryptoOperationException::class);
        $this->expectExceptionMessage('Decrypt failed');

        throw new CryptoOperationException('Decrypt failed');
    }

    public function test_crypto_operation_exception_is_catchable_as_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);

        throw new CryptoOperationException('failed');
    }

    public function test_crypto_operation_exception_stores_the_message_correctly(): void
    {
        $this->assertSame(
            'Encryption failed',
            (new CryptoOperationException('Encryption failed'))->getMessage()
        );
    }

    public function test_crypto_operation_exception_wraps_a_previous_exception(): void
    {
        $previous = new RuntimeException('Root cause');
        $exception = new CryptoOperationException('Crypto error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_invalid_credential_key_exception_extends_invalid_argument_exception(): void
    {
        $this->assertInstanceOf(InvalidArgumentException::class, new InvalidCredentialKeyException);
    }

    public function test_invalid_credential_key_exception_is_a_final_class(): void
    {
        $this->assertTrue((new ReflectionClass(InvalidCredentialKeyException::class))->isFinal());
    }

    public function test_invalid_credential_key_exception_is_throwable_and_catchable_as_its_own_type(): void
    {
        $this->expectException(InvalidCredentialKeyException::class);
        $this->expectExceptionMessage('invalid-key');

        throw new InvalidCredentialKeyException('invalid-key');
    }

    public function test_invalid_credential_key_exception_is_catchable_as_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        throw new InvalidCredentialKeyException('invalid-key');
    }

    public function test_invalid_credential_key_exception_does_not_extend_runtime_exception(): void
    {
        $this->assertNotInstanceOf(RuntimeException::class, new InvalidCredentialKeyException);
    }

    public function test_invalid_credential_key_exception_stores_the_message_correctly(): void
    {
        $this->assertSame('bad_KEY', (new InvalidCredentialKeyException('bad_KEY'))->getMessage());
    }

    public function test_invalid_credential_key_exception_accepts_a_custom_error_code(): void
    {
        $this->assertSame(422, (new InvalidCredentialKeyException('bad', 422))->getCode());
    }

    public function test_invalid_credential_key_exception_accepts_a_previous_exception(): void
    {
        $previous = new LogicException('Root');
        $exception = new InvalidCredentialKeyException('Wrapped', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
