<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-assert active-row uniqueness after SQLite table rebuilds performed by
 * later template-step migrations. PostgreSQL normally preserves the partial
 * index, but the operation is idempotent there as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->upPostgres(),
            'sqlite' => $this->upSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        // Corrective migration: active-row uniqueness is the intended schema.
    }

    private function upPostgres(): void
    {
        $indexes = DB::select(<<<'SQL'
            SELECT i.indexname, i.indexdef, (c.conname IS NOT NULL) AS is_constraint
            FROM pg_indexes i
            LEFT JOIN pg_constraint c ON c.conname = i.indexname AND c.contype = 'u'
            WHERE i.schemaname = current_schema()
              AND i.tablename = 'template_steps'
              AND i.indexdef LIKE 'CREATE UNIQUE INDEX%'
              AND i.indexname NOT LIKE '%_pkey'
            SQL);

        foreach ($indexes as $index) {
            if (stripos($index->indexdef, ' WHERE ') !== false) {
                continue;
            }

            if ($index->is_constraint) {
                DB::statement(sprintf('ALTER TABLE template_steps DROP CONSTRAINT %s', $index->indexname));
            } else {
                DB::statement(sprintf('DROP INDEX %s', $index->indexname));
            }

            DB::statement($index->indexdef.' WHERE deleted_at IS NULL');
        }
    }

    private function upSqlite(): void
    {
        $indexes = DB::select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'template_steps' AND sql LIKE 'CREATE UNIQUE INDEX%'",
        );

        foreach ($indexes as $index) {
            if (stripos($index->sql, ' where ') !== false) {
                continue;
            }

            DB::statement(sprintf('DROP INDEX "%s"', $index->name));
            DB::statement($index->sql.' where "deleted_at" is null');
        }
    }
};
