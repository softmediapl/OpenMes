<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_steps', function (Blueprint $table) {
            $table->text('hold_override_reason')->nullable()->after('min_duration_minutes');
            $table->foreignId('hold_overridden_by_id')
                ->nullable()
                ->after('hold_override_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('hold_overridden_at')->nullable()->after('hold_overridden_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hold_overridden_by_id');
            $table->dropColumn(['hold_override_reason', 'hold_overridden_at']);
        });
    }
};
