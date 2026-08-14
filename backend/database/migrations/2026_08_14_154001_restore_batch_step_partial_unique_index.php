<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite rebuilds batch_steps while adding the transport-unit requirement and
 * drops the soft-delete predicate from its unique index. Restore that predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $indexes = DB::select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'batch_steps' AND sql LIKE 'CREATE UNIQUE INDEX%'",
        );

        foreach ($indexes as $index) {
            if (stripos($index->sql, ' where ') !== false) {
                continue;
            }

            DB::statement(sprintf('DROP INDEX "%s"', $index->name));
            DB::statement($index->sql.' where "deleted_at" is null');
        }
    }

    public function down(): void
    {
        // The partial index is the canonical soft-delete-aware constraint.
    }
};
