<?php

namespace App\Services\Schedule;

use App\Models\Issue;
use App\Models\IssueType;
use App\Models\WorkOrder;
use App\Models\WorkOrderForecast;
use App\Services\IssueService;
use App\Support\SystemSetting;
use Illuminate\Support\Facades\DB;

/** Maintain one actionable schedule-risk issue per work order. */
final class ScheduleRiskAlertService
{
    public const ISSUE_TYPE_CODE = 'SCHEDULE_RISK';

    public function __construct(private readonly IssueService $issues) {}

    public function sync(WorkOrderForecast $forecast): ?Issue
    {
        $forecast->loadMissing('workOrder');
        $workOrder = $forecast->workOrder;
        if ($workOrder === null) {
            return null;
        }

        return DB::transaction(function () use ($forecast, $workOrder): ?Issue {
            $openIssue = Issue::query()
                ->where('work_order_id', $workOrder->id)
                ->where('source', Issue::SOURCE_SCHEDULE_FORECAST)
                ->open()
                ->lockForUpdate()
                ->first();

            $alertsEnabled = SystemSetting::boolean('forecast_alerts_enabled', true);
            $isRisk = in_array($forecast->risk_level, [
                WorkOrderForecast::RISK_AT_RISK,
                WorkOrderForecast::RISK_LATE,
            ], true);

            if ($alertsEnabled && $isRisk) {
                $attributes = $this->issueAttributes($forecast, $workOrder);
                if ($openIssue !== null) {
                    $openIssue->update($attributes);

                    return $openIssue->fresh(['issueType', 'workOrder']);
                }

                return $this->issues->createIssue([
                    ...$attributes,
                    'work_order_id' => $workOrder->id,
                    'issue_type_id' => $this->issueType($workOrder)->id,
                    'source' => Issue::SOURCE_SCHEDULE_FORECAST,
                    'reported_by_id' => null,
                ]);
            }

            if ($openIssue !== null && (
                ! $alertsEnabled
                || $forecast->risk_level === WorkOrderForecast::RISK_COMPLETE
                || $this->hasRecovered($forecast)
            )) {
                return $this->issues->resolveIssue(
                    $openIssue,
                    'Automatically resolved after the completion forecast recovered.',
                );
            }

            return $openIssue;
        });
    }

    /** @return array<string, mixed> */
    private function issueAttributes(WorkOrderForecast $forecast, WorkOrder $workOrder): array
    {
        $riskLabel = $forecast->risk_level === WorkOrderForecast::RISK_LATE
            ? 'late'
            : 'at risk';

        return [
            'title' => "Schedule forecast {$riskLabel}: {$workOrder->order_no}",
            'description' => $this->description($forecast),
            'custom_fields' => [
                'schedule_forecast_id' => $forecast->id,
                'schedule_risk_level' => $forecast->risk_level,
                'schedule_reason_codes' => $forecast->reason_codes ?? [],
            ],
        ];
    }

    private function description(WorkOrderForecast $forecast): string
    {
        $parts = [
            'Forecast end: '.$forecast->forecast_end_at->toIso8601String().'.',
        ];
        if ($forecast->customer_deadline_at !== null) {
            $parts[] = 'Customer deadline: '.$forecast->customer_deadline_at->toIso8601String().'.';
        }
        if ($forecast->variance_to_baseline_minutes !== null) {
            $parts[] = "Variance to approved plan: {$forecast->variance_to_baseline_minutes} minutes.";
        }
        if ($forecast->slack_to_deadline_minutes !== null) {
            $parts[] = "Deadline slack: {$forecast->slack_to_deadline_minutes} minutes.";
        }
        if (! empty($forecast->reason_codes)) {
            $parts[] = 'Reasons: '.implode(', ', $forecast->reason_codes).'.';
        }

        return implode(' ', $parts);
    }

    private function hasRecovered(WorkOrderForecast $forecast): bool
    {
        if ($forecast->risk_level !== WorkOrderForecast::RISK_ON_TRACK) {
            return false;
        }

        $margin = max(0, SystemSetting::integer('forecast_alert_recovery_margin_minutes', 30));
        $slackThreshold = max(0, SystemSetting::integer('forecast_at_risk_slack_minutes', 480));
        $varianceThreshold = max(1, SystemSetting::integer('forecast_variance_alert_minutes', 120));
        $slackRecovered = $forecast->slack_to_deadline_minutes === null
            || $forecast->slack_to_deadline_minutes > $slackThreshold + $margin;
        $varianceRecovered = $forecast->variance_to_baseline_minutes === null
            || $forecast->variance_to_baseline_minutes < max(0, $varianceThreshold - $margin);

        return $slackRecovered && $varianceRecovered;
    }

    private function issueType(WorkOrder $workOrder): IssueType
    {
        return IssueType::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                ['code' => self::ISSUE_TYPE_CODE],
                [
                    'tenant_id' => $workOrder->tenant_id,
                    'name' => 'Schedule Forecast Risk',
                    'severity' => 'HIGH',
                    'is_blocking' => false,
                    'is_active' => true,
                ],
            );
    }
}
