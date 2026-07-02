<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Models;

use Illuminate\Database\Eloquent\Model;
use Skillcraft\CredentialVault\Traits\HasVault;

/**
 * Convenience base model for applications that want vault support on an Eloquent model.
 *
 * Extend this class or use {@see HasVault} directly on your own models.
 */
abstract class VaultOwner extends Model
{
    use HasVault;
}
