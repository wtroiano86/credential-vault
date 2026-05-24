<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\SkillcraftCredentialVault;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

abstract class CredentialVaultTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(config('app.key'))) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
