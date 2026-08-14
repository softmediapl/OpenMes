<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_steps', function (Blueprint $table) {
            $table->boolean('quantity_reporting_required')->default(false)->after('requires_confirmation');
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->boolean('quantity_reporting_required')->default(false)->after('requires_confirmation');
            $table->decimal('input_quantity', 14, 4)->nullable()->after('run_time_per_unit_minutes');
            $table->decimal('good_quantity', 14, 4)->nullable()->after('input_quantity');
            $table->decimal('rework_quantity', 14, 4)->nullable()->after('good_quantity');
            $table->decimal('scrap_quantity', 14, 4)->nullable()->after('rework_quantity');
            $table->decimal('released_quantity', 14, 4)->nullable()->after('scrap_quantity');
            $table->foreignId('scrap_reason_id')->nullable()->after('released_quantity')
                ->constrained('scrap_reasons')->nullOnDelete();
            $table->text('quantity_notes')->nullable()->after('scrap_reason_id');
            $table->timestamp('quantity_reported_at')->nullable()->after('quantity_notes');
            $table->foreignId('quantity_reported_by_id')->nullable()->after('quantity_reported_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quantity_reported_by_id');
            $table->dropConstrainedForeignId('scrap_reason_id');
            $table->dropColumn([
                'quantity_reporting_required',
                'input_quantity',
                'good_quantity',
                'rework_quantity',
                'scrap_quantity',
                'released_quantity',
                'quantity_notes',
                'quantity_reported_at',
            ]);
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->dropColumn('quantity_reporting_required');
        });
    }
};
