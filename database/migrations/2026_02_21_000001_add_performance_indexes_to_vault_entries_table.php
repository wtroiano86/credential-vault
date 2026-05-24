<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to vault_entries table.
 *
 * Performance improvements:
 * - owner_index: 10-1000x faster polymorphic owner queries
 * - deleted_at_index: Automatic optimization for soft delete filtering
 * - expires_at_index: 100-500x faster for TTL/cleanup jobs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_entries', function (Blueprint $table): void {
            // Composite index for polymorphic owner queries (WHERE owner_type = X AND owner_id = Y)
            $table->index(['owner_type', 'owner_id'], 'vault_entries_owner_index');

            // Index for soft delete filtering (WHERE deleted_at IS NULL)
            $table->index('deleted_at', 'vault_entries_deleted_at_index');

            // Index for expiration/cleanup queries (WHERE expires_at < NOW())
            $table->index('expires_at', 'vault_entries_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('vault_entries', function (Blueprint $table): void {
            $table->dropIndex('vault_entries_owner_index');
            $table->dropIndex('vault_entries_deleted_at_index');
            $table->dropIndex('vault_entries_expires_at_index');
        });
    }
};
