<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_templates', function (Blueprint $table) {
            $table->foreignId('base_template_id')
                ->nullable()
                ->after('product_revision_id')
                ->constrained('process_templates')
                ->restrictOnDelete();
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->string('operation_code', 80)->nullable()->after('step_number');
            $table->string('insert_after_operation_code', 80)->nullable()->after('operation_code');
        });

        DB::table('template_steps')
            ->orderBy('id')
            ->get(['id', 'step_number'])
            ->each(function ($step): void {
                DB::table('template_steps')->where('id', $step->id)->update([
                    'operation_code' => 'OP_'.str_pad((string) $step->step_number, 3, '0', STR_PAD_LEFT),
                ]);
            });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->unique(['process_template_id', 'operation_code'], 'template_steps_operation_code_unique');
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->string('operation_code', 80)->nullable()->after('step_number');
        });

        Schema::create('process_template_step_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_template_id')->constrained()->cascadeOnDelete();
            $table->string('operation_code', 80);
            $table->timestamps();

            $table->unique(['process_template_id', 'operation_code'], 'process_template_exclusion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_template_step_exclusions');

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropColumn('operation_code');
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->dropUnique('template_steps_operation_code_unique');
            $table->dropColumn(['operation_code', 'insert_after_operation_code']);
        });

        Schema::table('process_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_template_id');
        });
    }
};
