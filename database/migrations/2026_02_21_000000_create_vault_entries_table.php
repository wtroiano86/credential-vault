<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vault_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('key_name');
            $table->text('encrypted_value');
            $table->json('metadata')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_type', 'owner_id', 'key_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vault_entries');
    }
};
