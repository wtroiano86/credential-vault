<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Exceptions;

use RuntimeException;

/**
 * Thrown when encryption or decryption operations fail.
 */
final class CryptoOperationException extends RuntimeException {}
