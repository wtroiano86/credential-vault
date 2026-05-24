<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Exceptions;

use RuntimeException;

/**
 * Thrown when vault package configuration is missing or invalid.
 */
final class ConfigurationException extends RuntimeException {}
