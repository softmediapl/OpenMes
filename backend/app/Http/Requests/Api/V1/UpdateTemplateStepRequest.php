<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OperationExecutionMode;
use App\Enums\OperationLaborMode;
use App\Http\Requests\Concerns\ValidatesTemplateStepInstruction;
use App\Http\Requests\Concerns\ValidatesTemplateStepQualityGate;
use App\Http\Requests\Concerns\ValidatesTemplateStepTiming;
use App\Models\TemplateStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateStepRequest extends FormRequest
{
    use ValidatesTemplateStepInstruction;
    use ValidatesTemplateStepQualityGate;
    use ValidatesTemplateStepTiming;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'instruction' => ['sometimes', 'nullable', 'string'],
            'requires_confirmation' => ['sometimes', 'boolean'],
            'quantity_reporting_required' => ['sometimes', 'boolean'],
            'requires_palletization' => ['sometimes', 'boolean'],
            'estimated_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'execution_mode' => ['sometimes', Rule::enum(OperationExecutionMode::class)],
            'min_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'setup_time_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'run_time_per_unit_minutes' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'required_operators' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'labor_mode' => ['sometimes', 'nullable', Rule::enum(OperationLaborMode::class)],
            'workstation_id' => ['sometimes', 'nullable', 'integer', 'exists:workstations,id'],
            'workstation_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('workstation_types', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'transport_unit_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('transport_unit_types', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'quality_check_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quality_check_templates', 'id')->whereNull('deleted_at')],
            'quality_gate_required' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $step = $this->route('template_step');
        $this->validateConfirmableInstruction(
            $validator,
            $step instanceof TemplateStep ? $step : null,
        );
        $this->validateTemplateStepTiming(
            $validator,
            $step instanceof TemplateStep ? $step : null,
        );
        $this->validateTemplateStepQualityGate(
            $validator,
            $step instanceof TemplateStep ? $step : null,
        );
    }
}
