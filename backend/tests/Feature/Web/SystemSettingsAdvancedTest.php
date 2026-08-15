<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Support\SystemSetting;
use App\Support\TimezoneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemSettingsAdvancedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        TimezoneRegistry::flush();
    }

    protected function tearDown(): void
    {
        TimezoneRegistry::flush();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'production_period' => 'none',
            'workflow_mode' => 'status',
            'schedule_view_mode' => 'weekly',
            'schedule_shifts_per_day' => 3,
            'schedule_horizon_weeks' => 6,
            'schedule_slot_minutes' => 15,
            'forecast_alerts_enabled' => true,
            'forecast_refresh_interval_minutes' => 15,
            'forecast_unplanned_downtime_minutes' => 120,
            'forecast_at_risk_slack_minutes' => 480,
            'forecast_variance_alert_minutes' => 120,
            'forecast_alert_recovery_margin_minutes' => 30,
            'realtime_mode' => 'polling',
            'production_tracking_mode' => 'per_operation',
            'production_qty_edit_policy' => 'none',
            'scanner_mode' => 'hid',
            'lot_picking_strategy' => 'fefo',
            'default_token_ttl_minutes' => 30,
            'app_timezone' => 'Europe/Warsaw',
            'tier_promotion_thresholds' => [
                'silver' => 5,
                'gold' => 20,
                'vip' => 50,
            ],
        ], $overrides);
    }

    public function test_only_admin_can_open_system_settings(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->get('/settings/system')->assertRedirect('/login');
        $this->actingAs($operator)->get('/settings/system')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/system')->assertOk();
    }

    public function test_admin_can_persist_runtime_settings(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/system', $this->payload([
                'allow_registration' => true,
                'block_negative_stock' => true,
                'lot_tracking_enabled' => true,
                'lot_picking_strategy' => 'fifo',
                'warehouse_auto_documents' => false,
                'schedule_slot_minutes' => 30,
                'forecast_alerts_enabled' => false,
                'forecast_refresh_interval_minutes' => 5,
                'forecast_unplanned_downtime_minutes' => 90,
                'forecast_at_risk_slack_minutes' => 360,
                'forecast_variance_alert_minutes' => 60,
                'forecast_alert_recovery_margin_minutes' => 15,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.system'));

        $this->assertTrue(SystemSetting::boolean('allow_registration'));
        $this->assertTrue(SystemSetting::boolean('block_negative_stock'));
        $this->assertTrue(SystemSetting::boolean('lot_tracking_enabled'));
        $this->assertSame('fifo', SystemSetting::get('lot_picking_strategy'));
        $this->assertFalse(SystemSetting::boolean('warehouse_auto_documents', true));
        $this->assertSame(30, SystemSetting::integer('schedule_slot_minutes'));
        $this->assertFalse(SystemSetting::boolean('forecast_alerts_enabled', true));
        $this->assertSame(5, SystemSetting::integer('forecast_refresh_interval_minutes'));
        $this->assertSame(90, SystemSetting::integer('forecast_unplanned_downtime_minutes'));
        $this->assertSame(360, SystemSetting::integer('forecast_at_risk_slack_minutes'));
        $this->assertSame(60, SystemSetting::integer('forecast_variance_alert_minutes'));
        $this->assertSame(15, SystemSetting::integer('forecast_alert_recovery_margin_minutes'));
        $this->assertSame('Europe/Warsaw', TimezoneRegistry::stored());
        $this->assertSame(
            ['silver' => 5, 'gold' => 20, 'vip' => 50],
            SystemSetting::get('tier_promotion_thresholds')
        );
    }

    public function test_invalid_advanced_values_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/system', $this->payload([
                'lot_picking_strategy' => 'random',
                'schedule_slot_minutes' => 7,
                'forecast_refresh_interval_minutes' => 0,
                'forecast_unplanned_downtime_minutes' => 0,
                'forecast_at_risk_slack_minutes' => -1,
                'forecast_variance_alert_minutes' => 0,
                'forecast_alert_recovery_margin_minutes' => -1,
                'app_timezone' => 'Factory/Warsaw',
                'tier_promotion_thresholds' => [
                    'silver' => 20,
                    'gold' => 10,
                    'vip' => 5,
                ],
            ]))
            ->assertSessionHasErrors([
                'lot_picking_strategy',
                'schedule_slot_minutes',
                'forecast_refresh_interval_minutes',
                'forecast_unplanned_downtime_minutes',
                'forecast_at_risk_slack_minutes',
                'forecast_variance_alert_minutes',
                'forecast_alert_recovery_margin_minutes',
                'app_timezone',
                'tier_promotion_thresholds',
            ]);
    }

    public function test_settings_catalog_hides_unregistered_values(): void
    {
        DB::table('system_settings')->insert([
            'key' => 'future_private_setting',
            'value' => json_encode('sensitive-value'),
            'description' => 'A future setting without a validated editor',
        ]);

        $response = $this->actingAs($this->admin)->get('/settings/system');
        $catalog = collect($response->getOriginalContent()->getData()['page']['props']['settingsCatalog']);

        $unknown = $catalog->firstWhere('key', 'future_private_setting');
        $timezone = $catalog->firstWhere('key', TimezoneRegistry::SETTING_KEY);
        $forecastRefresh = $catalog->firstWhere('key', 'forecast_refresh_interval_minutes');

        $this->assertNotNull($unknown);
        $this->assertTrue($unknown['valueHidden']);
        $this->assertNull($unknown['value']);
        $this->assertFalse($unknown['editable']);
        $this->assertSame(TimezoneRegistry::current(), $timezone['value']);
        $this->assertTrue($timezone['editable']);
        $this->assertSame(15, $forecastRefresh['value']);
        $this->assertTrue($forecastRefresh['editable']);
        $this->assertSame('Schedule tab', $forecastRefresh['managedAt']);
    }
}
