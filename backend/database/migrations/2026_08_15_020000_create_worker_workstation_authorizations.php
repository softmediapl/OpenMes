<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_workstation_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->date('authorized_from')->nullable();
            $table->date('authorized_until')->nullable();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['worker_id', 'workstation_id'], 'worker_workstation_authorization_unique');
            $table->index(
                ['workstation_id', 'authorized_from', 'authorized_until'],
                'worker_workstation_authorization_window_idx',
            );
        });

        $now = now();
        DB::table('workers')
            ->whereNotNull('workstation_id')
            ->orderBy('id')
            ->get(['id', 'workstation_id'])
            ->each(function ($worker) use ($now): void {
                DB::table('worker_workstation_authorizations')->insertOrIgnore([
                    'worker_id' => $worker->id,
                    'workstation_id' => $worker->workstation_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_workstation_authorizations');
    }
};
