<?php

declare(strict_types=1);

namespace Skillcraft\CredentialVault\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Skillcraft\CredentialVault\Providers\CredentialVaultServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CredentialVaultServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        if (empty($app['config']->get('app.key'))) {
            $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        }
    }
}
