<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested credential key does not exist for an owner.
 */
final class CredentialNotFoundException extends RuntimeException {}
