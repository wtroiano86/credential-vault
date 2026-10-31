<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Console;

use Illuminate\Console\Command;
use Skillcraft\CredentialVault\Contracts\VaultEncryptionInterface;
use Skillcraft\CredentialVault\Exceptions\CredentialPersistenceException;
use Skillcraft\CredentialVault\Services\CredentialCiphertext;
use Skillcraft\CredentialVault\Support\VaultConfig;
use Throwable;

/** Upgrade all rows, including expired and soft-deleted credentials. */
final class UpgradeEncryption extends Command
{
    protected $signature = 'credential-vault:upgrade-encryption {--dry-run : Validate all rows without saving changes}';

    protected $description = 'Bind legacy and scv2 ciphertext to its owner, credential name, database, and table prefix';

    public function handle(VaultEncryptionInterface $encryption): int
    {
        $ciphertext = new CredentialCiphertext($encryption);
        $model = VaultConfig::model();
        $prototype = new $model;
        $upgraded = 0;
        $failed = 0;

        foreach ($prototype->newQuery()->withTrashed()->select($prototype->getKeyName())->lazyById(100) as $candidate) {
            try {
                $changed = $prototype->getConnection()->transaction(function () use ($candidate, $prototype, $ciphertext): bool {
                    $entry = $prototype->newQuery()->withTrashed()->lockForUpdate()->whereKey($candidate->getKey())->first();

                    if ($entry === null) {
                        return false;
                    }

                    $replacement = $ciphertext->upgrade($entry);

                    if ($replacement === null) {
                        return false;
                    }

                    if (! $this->option('dry-run')) {
                        $entry->timestamps = false;
                        $entry->setAttribute('encrypted_value', $replacement);

                        if (! $entry->save()) {
                            throw new CredentialPersistenceException('Ciphertext upgrade was cancelled.');
                        }
                    }

                    return true;
                }, 3);
                $upgraded += (int) $changed;
            } catch (Throwable) {
                // Driver exceptions may contain sensitive data; never print them.
                $failed++;
                $this->error('A credential could not be upgraded.');
            }
        }

        $this->info(($this->option('dry-run') ? 'Would upgrade: ' : 'Upgraded: ').$upgraded.'. Failed: '.$failed.'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
