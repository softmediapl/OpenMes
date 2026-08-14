<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'allow_registration' => [false, 'Allow public user registration'],
            'warehouse_auto_documents' => [true, 'Generate draft stock documents when a work order is completed'],
        ];

        foreach ($settings as $key => [$value, $description]) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', ['allow_registration', 'warehouse_auto_documents'])
            ->delete();
    }
};
