<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_supervisor_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_step_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('mode', 32);
            $table->text('reason');
            $table->timestamp('authorized_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['workstation_id', 'batch_step_id', 'action', 'consumed_at'], 'panel_supervisor_grant_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_supervisor_authorizations');
    }
};
