<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_steps', function (Blueprint $table) {
            $table->foreignId('quality_check_template_id')
                ->nullable()
                ->after('transport_unit_type_id')
                ->constrained()
                ->nullOnDelete();
            $table->boolean('quality_gate_required')
                ->default(false)
                ->after('quality_check_template_id');
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->foreignId('quality_check_template_id')
                ->nullable()
                ->after('transport_unit_type_id')
                ->constrained()
                ->nullOnDelete();
            $table->boolean('quality_gate_required')
                ->default(false)
                ->after('quality_check_template_id');
            $table->json('quality_check_specification')
                ->nullable()
                ->after('quality_gate_required');
        });

        Schema::table('quality_checks', function (Blueprint $table) {
            $table->foreignId('batch_step_id')
                ->nullable()
                ->after('batch_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('issue_id')
                ->nullable()
                ->after('quality_check_template_id')
                ->constrained()
                ->nullOnDelete();
            $table->index(['batch_step_id', 'checked_at']);
        });

        $this->restoreSqliteActiveUniqueIndexes();
    }

    public function down(): void
    {
        Schema::table('quality_checks', function (Blueprint $table) {
            $table->dropIndex(['batch_step_id', 'checked_at']);
            $table->dropConstrainedForeignId('issue_id');
            $table->dropConstrainedForeignId('batch_step_id');
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quality_check_template_id');
            $table->dropColumn(['quality_gate_required', 'quality_check_specification']);
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quality_check_template_id');
            $table->dropColumn('quality_gate_required');
        });

        $this->restoreSqliteActiveUniqueIndexes();
    }

    /**
     * SQLite rebuilds a table when foreign-key columns are added or removed.
     * That rebuild recreates schema-defined unique indexes without their
     * deleted_at predicate, so archived step revisions would block regeneration.
     */
    private function restoreSqliteActiveUniqueIndexes(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['template_steps', 'batch_steps'] as $table) {
            $indexes = DB::select(
                "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql LIKE 'CREATE UNIQUE INDEX%'",
                [$table],
            );

            foreach ($indexes as $index) {
                if (stripos($index->sql, ' where ') !== false) {
                    continue;
                }

                DB::statement(sprintf('DROP INDEX "%s"', $index->name));
                DB::statement($index->sql.' where "deleted_at" is null');
            }
        }
    }
};
