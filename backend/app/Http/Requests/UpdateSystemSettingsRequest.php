<?php

namespace App\Http\Requests;

use App\Support\ModuleRegistry;
use App\Support\SystemSetting;
use App\Support\TierPromotionRegistry;
use App\Support\TimezoneRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSystemSettingsRequest extends FormRequest
{
    /** Route middleware (role:Admin) gates access. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $defaults = [
            'lot_picking_strategy' => SystemSetting::get('lot_picking_strategy', 'fefo'),
            'default_token_ttl_minutes' => SystemSetting::integer(
                'default_token_ttl_minutes',
                config('openmmes.default_token_ttl_minutes', 15)
            ),
            'app_timezone' => TimezoneRegistry::current(),
            'schedule_slot_minutes' => SystemSetting::integer('schedule_slot_minutes', 15),
            'forecast_alerts_enabled' => SystemSetting::boolean('forecast_alerts_enabled', true),
            'forecast_refresh_interval_minutes' => SystemSetting::integer('forecast_refresh_interval_minutes', 15),
            'forecast_unplanned_downtime_minutes' => SystemSetting::integer('forecast_unplanned_downtime_minutes', 120),
            'forecast_at_risk_slack_minutes' => SystemSetting::integer('forecast_at_risk_slack_minutes', 480),
            'forecast_variance_alert_minutes' => SystemSetting::integer('forecast_variance_alert_minutes', 120),
            'forecast_alert_recovery_margin_minutes' => SystemSetting::integer('forecast_alert_recovery_margin_minutes', 30),
            'tier_promotion_thresholds' => TierPromotionRegistry::thresholds(),
        ];

        $input = $this->all();

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $input)) {
                $this->merge([$key => $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'production_period' => ['required', Rule::in(['none', 'weekly', 'monthly'])],
            'allow_overproduction' => ['nullable', 'boolean'],
            'force_sequential_steps' => ['nullable', 'boolean'],
            'workstation_routing_enabled' => ['nullable', 'boolean'],
            'backflush_on_pallet_creation' => ['nullable', 'boolean'],
            'block_negative_stock' => ['nullable', 'boolean'],
            'lot_tracking_enabled' => ['nullable', 'boolean'],
            'lot_picking_strategy' => ['required', Rule::in(['fefo', 'fifo', 'lifo', 'manual'])],
            'warehouse_auto_documents' => ['nullable', 'boolean'],
            'workflow_mode' => ['required', Rule::in(['status', 'board_status'])],
            'pin_login_enabled' => ['nullable', 'boolean'],
            'panel_identity_mode' => ['required', Rule::in(['username_pin', 'pin_only', 'list_pin'])],
            'panel_pin_length' => ['required', 'integer', 'min:9', 'max:12'],
            'panel_pin_group_size' => ['required', 'integer', 'min:1', 'max:4'],
            'panel_operator_session_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'panel_supervisor_mode' => ['required', Rule::in(['inline_pin', 'session_takeover', 'remote_only'])],
            'panel_help_issue_type_id' => ['nullable', 'integer', 'exists:issue_types,id'],
            'allow_registration' => ['nullable', 'boolean'],
            'default_token_ttl_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'language' => ['nullable', Rule::in(array_keys(config('app.available_locales', [])))],
            'app_timezone' => ['required', 'string', Rule::in(TimezoneRegistry::identifiers())],
            'schedule_view_mode' => ['required', Rule::in(['weekly', 'daily', 'monthly'])],
            'schedule_shifts_per_day' => ['required', 'integer', Rule::in([1, 2, 3, 4])],
            'schedule_horizon_weeks' => ['required', 'integer', 'min:1', 'max:52'],
            'schedule_show_weekends' => ['nullable', 'boolean'],
            'schedule_slot_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 30, 60])],
            'forecast_alerts_enabled' => ['required', 'boolean'],
            'forecast_refresh_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'forecast_unplanned_downtime_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'forecast_at_risk_slack_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'forecast_variance_alert_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'forecast_alert_recovery_margin_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'realtime_mode' => ['required', Rule::in(['polling', 'off'])],
            'production_tracking_mode' => ['required', Rule::in(['per_operation', 'cumulative', 'hybrid'])],
            'cors_allowed_origins' => ['nullable', 'string', 'max:1000'],
            'cors_allowed_methods' => ['nullable', 'string', 'max:200'],
            'cors_max_age' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'production_qty_edit_policy' => ['required', Rule::in(['none', 'timed', 'full'])],
            'production_qty_edit_window_minutes' => ['required_if:production_qty_edit_policy,timed', 'integer', 'min:1', 'max:60'],
            'scanner_mode' => ['required', Rule::in(['hid', 'manual'])],
            'standard_weekly_hours' => ['nullable', 'numeric', 'min:1', 'max:168'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'default_pay_type' => ['nullable', Rule::in(['hourly', 'weekly', 'piece_rate'])],
            'default_pay_rate' => ['nullable', 'numeric', 'min:0'],
            'tier_promotion_thresholds' => ['required', 'array:silver,gold,vip'],
            'tier_promotion_thresholds.silver' => ['required', 'integer', 'min:1'],
            'tier_promotion_thresholds.gold' => ['required', 'integer', 'min:1'],
            'tier_promotion_thresholds.vip' => ['required', 'integer', 'min:1'],
            'enabled_modules' => ['nullable', 'array'],
            'enabled_modules.*' => ['string', Rule::in(ModuleRegistry::optionalKeys())],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $thresholds = $this->input('tier_promotion_thresholds', []);
                if (! is_array($thresholds)) {
                    return;
                }

                $silver = (int) ($thresholds['silver'] ?? 0);
                $gold = (int) ($thresholds['gold'] ?? 0);
                $vip = (int) ($thresholds['vip'] ?? 0);

                if (! ($silver < $gold && $gold < $vip)) {
                    $validator->errors()->add(
                        'tier_promotion_thresholds',
                        __('Customer tier thresholds must be strictly ascending.')
                    );
                }
            },
        ];
    }
}
