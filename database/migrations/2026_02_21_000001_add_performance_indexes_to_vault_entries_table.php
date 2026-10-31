<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to vault_entries table.
 *
 * Supports owner lookups and deletion/expiry filters. Actual performance depends
 * on the database, query plan, and data distribution. No cleanup job is bundled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('packages.credential-vault.general.table', 'scp_vault_entries'), function (Blueprint $table): void {
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
        Schema::table(config('packages.credential-vault.general.table', 'scp_vault_entries'), function (Blueprint $table): void {
            $table->dropIndex('vault_entries_owner_index');
            $table->dropIndex('vault_entries_deleted_at_index');
            $table->dropIndex('vault_entries_expires_at_index');
        });
    }
};
