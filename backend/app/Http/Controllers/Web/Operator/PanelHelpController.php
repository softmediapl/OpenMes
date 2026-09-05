<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Models\BatchStep;
use App\Models\IssueType;
use App\Models\WorkOrder;
use App\Services\CustomFieldService;
use App\Services\IssueService;
use App\Services\Operator\WorkstationContext;
use App\Support\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PanelHelpController extends Controller
{
    public function __construct(private readonly WorkstationContext $workstations) {}

    public function report(Request $request, CustomFieldService $customFields, IssueService $issues)
    {
        $data = $request->validate(array_merge($this->contextRules(), [
            'issue_type_id' => ['required', 'integer', Rule::exists('issue_types', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], $customFields->rules('issue')), [], $customFields->attributeNames('issue'));

        $issues->createIssue([
            ...$this->issueContext($request, $data),
            'issue_type_id' => $data['issue_type_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'reported_by_id' => $request->user()->id,
            'custom_fields' => $customFields->touched($request) ? ($customFields->fromRequest($request, 'issue') ?: null) : null,
        ]);

        return back()->with('success', __('Issue reported successfully.'));
    }

    public function supervisor(Request $request, IssueService $issues)
    {
        $data = $request->validate(array_merge($this->contextRules(), [
            'description' => ['nullable', 'string', 'max:1000'],
        ]));
        $context = $this->issueContext($request, $data);

        $typeId = SystemSetting::get('panel_help_issue_type_id');
        $type = $typeId ? IssueType::active()->find($typeId) : null;
        if (! $type) {
            throw ValidationException::withMessages(['supervisor' => __('Configure the supervisor help issue type in system settings.')]);
        }

        $issues->createIssue([
            ...$context,
            'issue_type_id' => $type->id,
            'title' => __('Supervisor requested from operator panel'),
            'description' => $data['description'] ?? null,
            'reported_by_id' => $request->user()->id,
        ]);

        return back()->with('success', __('Supervisor request sent.'));
    }

    private function contextRules(): array
    {
        return [
            'work_order_id' => ['nullable', 'integer', 'exists:work_orders,id', 'required_with:batch_step_id'],
            'batch_step_id' => ['nullable', 'integer', 'exists:batch_steps,id'],
        ];
    }

    private function issueContext(Request $request, array $data): array
    {
        // The authenticated terminal/session determines the station, never form input.
        $station = $this->workstations->currentWorkstation($request);
        abort_unless($station, 403, __('Select a workstation first.'));
        $order = isset($data['work_order_id']) ? WorkOrder::findOrFail($data['work_order_id']) : null;
        $step = isset($data['batch_step_id']) ? BatchStep::findOrFail($data['batch_step_id']) : null;

        if ($step) {
            abort_unless((int) $step->batch?->work_order_id === (int) $order?->id
                && (int) $step->batch?->currentStep()?->id === (int) $step->id
                && $this->workstations->workstationCanOperateStep($station, $step), 403);
        } elseif ($order) {
            $order->loadMissing('batches.steps');
            abort_unless($order->batches->contains(function ($batch) use ($station) {
                $current = $batch->currentStep();

                return $current && $this->workstations->workstationCanOperateStep($station, $current);
            }), 403);
        }

        return ['workstation_id' => $station->id, 'work_order_id' => $order?->id, 'batch_step_id' => $step?->id];
    }
}
