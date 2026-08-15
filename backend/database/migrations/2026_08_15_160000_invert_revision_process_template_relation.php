<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('process_templates', 'product_revision_id')) {
                $table->foreignId('product_revision_id')
                    ->nullable()
                    ->after('product_type_id')
                    ->constrained('product_revisions')
                    ->nullOnDelete();
                $table->index(['product_type_id', 'product_revision_id', 'is_active'], 'process_templates_type_revision_active_idx');
            }
        });

        // Do not infer the new relation from product_revisions.process_template_id.
        // Existing installations must deliberately assign templates to revisions,
        // because a product revision represents a product generation while process
        // templates/BOMs can represent several color or decoration variants.
        Schema::table('product_revisions', function (Blueprint $table) {
            if (Schema::hasColumn('product_revisions', 'process_template_id')) {
                $table->dropForeign(['process_template_id']);
                $table->dropColumn('process_template_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_revisions', function (Blueprint $table) {
            if (! Schema::hasColumn('product_revisions', 'process_template_id')) {
                $table->foreignId('process_template_id')
                    ->nullable()
                    ->after('lifecycle_status')
                    ->constrained('process_templates')
                    ->nullOnDelete();
            }
        });

        Schema::table('process_templates', function (Blueprint $table) {
            if (Schema::hasColumn('process_templates', 'product_revision_id')) {
                $table->dropIndex('process_templates_type_revision_active_idx');
                $table->dropForeign(['product_revision_id']);
                $table->dropColumn('product_revision_id');
            }
        });
    }
};
