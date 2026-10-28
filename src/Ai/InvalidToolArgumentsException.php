<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Ai;

use InvalidArgumentException;

/** @internal Distinguish model argument errors from application/policy failures. */
final class InvalidToolArgumentsException extends InvalidArgumentException {}
