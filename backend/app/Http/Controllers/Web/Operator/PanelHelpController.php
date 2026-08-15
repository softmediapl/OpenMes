<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueType;
use App\Models\BatchStep;
use App\Models\WorkOrder;
use App\Services\Operator\WorkstationContext;
use App\Support\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PanelHelpController extends Controller
{
    public function supervisor(Request $request, WorkstationContext $workstations)
    {
        $data = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'batch_step_id' => ['nullable', 'integer', 'exists:batch_steps,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $workOrder = WorkOrder::findOrFail($data['work_order_id']);
        abort_unless($workstations->canAccessWorkOrder($request, $workOrder), 403);
        $step = isset($data['batch_step_id']) ? BatchStep::findOrFail($data['batch_step_id']) : null;
        abort_if($step && ((int) $step->batch?->work_order_id !== (int) $workOrder->id || ! $workstations->canAccessStep($request, $step)), 403);

        $typeId = SystemSetting::get('panel_help_issue_type_id');
        $type = $typeId ? IssueType::active()->find($typeId) : null;
        if (! $type) {
            throw ValidationException::withMessages(['supervisor' => __('Configure the supervisor help issue type in system settings.')]);
        }

        Issue::create([
            'work_order_id' => $workOrder->id,
            'batch_step_id' => $step?->id,
            'issue_type_id' => $type->id,
            'title' => __('Supervisor requested from operator panel'),
            'description' => $data['description'] ?? null,
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $request->user()->id,
            'reported_at' => now(),
        ]);
        if ($type->is_blocking) {
            $workOrder->update(['status' => WorkOrder::STATUS_BLOCKED]);
        }

        return back()->with('success', __('Supervisor request sent.'));
    }
}
