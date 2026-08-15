<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OperationExecutionMode;
use App\Enums\OperationLaborMode;
use App\Http\Requests\Concerns\ValidatesTemplateStepInstruction;
use App\Http\Requests\Concerns\ValidatesTemplateStepQualityGate;
use App\Http\Requests\Concerns\ValidatesTemplateStepTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateStepRequest extends FormRequest
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
            'step_number' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'instruction' => ['nullable', 'string'],
            'requires_confirmation' => ['sometimes', 'boolean'],
            'quantity_reporting_required' => ['sometimes', 'boolean'],
            'requires_palletization' => ['sometimes', 'boolean'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'execution_mode' => ['sometimes', Rule::enum(OperationExecutionMode::class)],
            'min_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'setup_time_minutes' => ['nullable', 'integer', 'min:0'],
            'run_time_per_unit_minutes' => ['nullable', 'numeric', 'min:0'],
            'required_operators' => ['nullable', 'integer', 'min:1'],
            'labor_mode' => ['nullable', Rule::enum(OperationLaborMode::class)],
            'workstation_id' => ['nullable', 'integer', 'exists:workstations,id'],
            'workstation_type_id' => ['nullable', 'integer', Rule::exists('workstation_types', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'transport_unit_type_id' => ['nullable', 'integer', Rule::exists('transport_unit_types', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'quality_check_template_id' => ['nullable', 'integer', Rule::exists('quality_check_templates', 'id')->whereNull('deleted_at')],
            'quality_gate_required' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $this->validateConfirmableInstruction($validator);
        $this->validateTemplateStepTiming($validator);
        $this->validateTemplateStepQualityGate($validator);
    }
}
