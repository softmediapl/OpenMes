<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_templates', function (Blueprint $table) {
            $table->decimal('preferred_batch_quantity', 14, 4)->nullable();
            $table->decimal('min_batch_quantity', 14, 4)->nullable();
            $table->decimal('max_batch_quantity', 14, 4)->nullable();
            $table->decimal('batch_quantity_multiple', 14, 4)->nullable();
            $table->boolean('allow_partial_final_batch')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('process_templates', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_batch_quantity',
                'min_batch_quantity',
                'max_batch_quantity',
                'batch_quantity_multiple',
                'allow_partial_final_batch',
            ]);
        });
    }
};
