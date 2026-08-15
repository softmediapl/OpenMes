<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_reason_workstation_type', function (Blueprint $table) {
            $table->foreignId('scrap_reason_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workstation_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['scrap_reason_id', 'workstation_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrap_reason_workstation_type');
    }
};
