<?php

declare(strict_types=1);

namespace Tests\Feature\Packages\SkillcraftCredentialVault\Support;

use Illuminate\Database\Eloquent\Model;
use Skillcraft\CredentialVault\Traits\HasVault;

final class VaultTestModels
{
    public static function owner(string $type = 'App\\Models\\User', string $id = 'user-1'): Model
    {
        $model = new class extends Model
        {
            protected $table = 'users';

            public string $morphType = 'App\\Models\\User';

            public string $morphId = 'user-1';

            public function getMorphClass(): string
            {
                return $this->morphType;
            }

            public function getKey(): mixed
            {
                return $this->morphId;
            }
        };

        $model->exists = true;
        $model->morphType = $type;
        $model->morphId = $id;

        return $model;
    }

    public static function hasVaultModel(): Model
    {
        return new class extends Model
        {
            use HasVault;

            protected $table = 'users';

            public function getMorphClass(): string
            {
                return 'App\\Models\\User';
            }

            public function getKey(): mixed
            {
                return 'user-1';
            }
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function entryAttributes(array $overrides = []): array
    {
        return array_merge([
            'owner_type' => 'App\\Models\\User',
            'owner_id' => 'user-1',
            'key_name' => 'api_key',
            'encrypted_value' => 'encrypted-payload',
            'metadata' => [],
        ], $overrides);
    }
}
