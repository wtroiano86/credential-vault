<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a credential key does not match the configured format rules.
 */
final class InvalidCredentialKeyException extends InvalidArgumentException {}
