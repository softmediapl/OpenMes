<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTINGS = [
        'forecast_alerts_enabled' => [true, 'Create and resolve schedule-risk alerts from rolling forecasts.'],
        'forecast_refresh_interval_minutes' => [15, 'Minimum interval between equivalent rolling forecast snapshots.'],
        'forecast_unplanned_downtime_minutes' => [120, 'Fallback duration for an active downtime without an expected end.'],
        'forecast_at_risk_slack_minutes' => [480, 'Open a schedule-risk alert when deadline slack reaches this threshold.'],
        'forecast_variance_alert_minutes' => [120, 'Open a schedule-risk alert when forecast variance reaches this threshold.'],
        'forecast_alert_recovery_margin_minutes' => [30, 'Additional recovery margin required before an alert is resolved.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as $key => [$value, $description]) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'description' => $description,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
    }
};
