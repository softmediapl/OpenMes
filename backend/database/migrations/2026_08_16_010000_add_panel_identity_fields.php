<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_lookup', 64)->nullable()->after('pin');
            $table->timestamp('pin_rotated_at')->nullable()->after('pin_lookup');
            $table->unique(['tenant_id', 'pin_lookup'], 'users_tenant_pin_lookup_unique');
        });

        Schema::table('workstations', function (Blueprint $table) {
            $table->string('panel_supervisor_mode', 32)->nullable()->after('capacity_slots');
        });

        $settings = [
            'panel_identity_mode' => ['username_pin', 'Operator panel identity mode.'],
            'panel_pin_length' => [9, 'Length of generated numeric panel credentials.'],
            'panel_pin_group_size' => [3, 'Visual grouping size for manually entered panel credentials.'],
            'panel_operator_session_hours' => [12, 'Maximum personal operator session duration.'],
            'panel_supervisor_mode' => ['inline_pin', 'Default supervisor authorization mode on operator panels.'],
            'panel_help_issue_type_id' => [null, 'Issue type used by the panel supervisor-help action.'],
        ];

        foreach ($settings as $key => [$value, $description]) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'panel_identity_mode',
            'panel_pin_length',
            'panel_pin_group_size',
            'panel_operator_session_hours',
            'panel_supervisor_mode',
            'panel_help_issue_type_id',
        ])->delete();

        Schema::table('workstations', fn (Blueprint $table) => $table->dropColumn('panel_supervisor_mode'));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_pin_lookup_unique');
            $table->dropColumn(['pin_lookup', 'pin_rotated_at']);
        });
    }
};
