<?php

namespace App\Services\ProcessTemplate;

use App\Models\ProcessTemplate;

class SnapshotService
{
    public function __construct(private CompositionService $composition) {}

    /**
     * Create a JSONB snapshot of a process template.
     *
     * This snapshot is immutable and stored with the work order,
     * so changes to the template don't affect existing work orders.
     */
    public function createSnapshot(ProcessTemplate $template): array
    {
        $template->load([
            'dependencies.predecessor',
            'dependencies.successor',
            'bomItems.material.materialType',
            'bomItems.templateStep',
            'baseTemplate',
            'stepExclusions',
        ]);

        $resolvedSteps = $this->composition->resolveSteps($template);
        $stepsById = $resolvedSteps->keyBy(fn (array $row) => $row['step']->id);
        $dependencyMode = $template->base_template_id === null && $template->dependency_mode === 'explicit'
            ? 'explicit'
            : 'sequential';
        if ($dependencyMode === 'explicit') {
            $dependencies = $template->dependencies->map(function ($dependency) use ($stepsById) {
                $predecessor = $stepsById->get($dependency->predecessor_step_id);
                $successor = $stepsById->get($dependency->successor_step_id);

                return $predecessor && $successor ? [
                    'predecessor_step_number' => $predecessor['step_number'],
                    'successor_step_number' => $successor['step_number'],
                    'dependency_type' => $dependency->dependency_type,
                    'lag_minutes' => $dependency->lag_minutes,
                ] : null;
            })->filter()->values();
        } else {
            $orderedSteps = $resolvedSteps->values();
            $dependencies = collect();
            for ($index = 1; $index < $orderedSteps->count(); $index++) {
                $dependencies->push([
                    'predecessor_step_number' => $orderedSteps[$index - 1]['step_number'],
                    'successor_step_number' => $orderedSteps[$index]['step_number'],
                    'dependency_type' => \App\Models\TemplateStepDependency::TYPE_FINISH_TO_START,
                    'lag_minutes' => 0,
                ]);
            }
        }

        $effectiveNumbersByStepId = $resolvedSteps->mapWithKeys(
            fn (array $row) => [$row['step']->id => $row['step_number']]
        );

        return [
            'template_id' => $template->id,
            'template_name' => $template->name,
            'template_version' => $template->version,
            'composition' => $template->baseTemplate ? [
                'base_template_id' => $template->baseTemplate->id,
                'base_template_name' => $template->baseTemplate->name,
                'base_template_version' => $template->baseTemplate->version,
                'inherited_steps' => $resolvedSteps->where('source', 'inherited')->count(),
                'overridden_steps' => $resolvedSteps->where('source', 'override')->count(),
                'local_steps' => $resolvedSteps->where('source', 'local')->count(),
                'omitted_operation_codes' => $template->stepExclusions->pluck('operation_code')->values()->all(),
            ] : null,
            'product_type_id' => $template->product_type_id,
            'product_unit_of_measure' => $template->productType->unit_of_measure,
            'product_quantity_precision' => $template->productType->quantity_precision,
            'dependency_mode' => $dependencyMode,
            'dependencies' => $dependencies->toArray(),
            'batch_policy' => $template->batchPolicySnapshot(),
            'packaging_policy' => $template->packagingPolicySnapshot(),
            'steps' => $resolvedSteps->map(function (array $resolved) {
                $step = $resolved['step'];

                return [
                    'step_number' => $resolved['step_number'],
                    'operation_code' => $step->operation_code,
                    'composition_source' => $resolved['source'],
                    'source_template_id' => $resolved['source_template_id'],
                    'source_template_step_id' => $step->id,
                    'name' => $step->name,
                    'instruction' => $step->effectiveInstruction(),
                    'requires_confirmation' => (bool) $step->requires_confirmation
                        && $step->hasConfirmableInstructionContent(),
                    'quantity_reporting_required' => (bool) $step->quantity_reporting_required,
                    'requires_palletization' => (bool) $step->requires_palletization,
                    'estimated_duration_minutes' => $step->estimated_duration_minutes,
                    'execution_mode' => $step->execution_mode->value,
                    'min_duration_minutes' => $step->min_duration_minutes,
                    'setup_time_minutes' => $step->setup_time_minutes,
                    'run_time_per_unit_minutes' => $step->run_time_per_unit_minutes,
                    'required_operators' => $step->effectiveRequiredOperators(),
                    'labor_mode' => $step->effectiveLaborMode()->value,
                    'required_skill_ids' => $step->effectiveRequiredSkillIds(),
                    'workstation_id' => $step->workstation_id,
                    'workstation_name' => $step->workstation?->name,
                    'workstation_capacity_slots' => $step->workstation?->capacity_slots,
                    'workstation_type_id' => $step->effectiveWorkstationType(),
                    'transport_unit_type_id' => $step->transport_unit_type_id,
                    'quality_check_template_id' => $step->quality_check_template_id,
                    'quality_gate_required' => (bool) $step->quality_gate_required,
                    'quality_check_specification' => $step->quality_gate_required && $step->qualityCheckTemplate
                        ? [
                            'name' => $step->qualityCheckTemplate->name,
                            'required_checks' => max(1, (int) $step->qualityCheckTemplate->min_checks_per_batch),
                            'samples_per_check' => max(1, (int) $step->qualityCheckTemplate->samples_per_check),
                            'parameters' => $step->qualityCheckTemplate->parameters ?? [],
                        ]
                        : null,
                    'transport_unit_capacity_quantity' => $step->transportUnitType?->default_capacity_quantity !== null
                        ? (float) $step->transportUnitType->default_capacity_quantity
                        : null,
                    'transport_unit_unit_of_measure' => $step->transportUnitType?->unit_of_measure,
                    'is_optional' => (bool) $step->is_optional,
                    'variant_group' => $step->variant_group,
                    'is_default_variant' => (bool) $step->is_default_variant,
                ];
            })->toArray(),
            'bom' => $template->bomItems->map(function ($item) use ($template, $effectiveNumbersByStepId) {
                return [
                    'material_id' => $item->material_id,
                    'material_code' => $item->material->code,
                    'material_name' => $item->material->name,
                    'material_type' => $item->material->materialType?->code,
                    'tracking_type' => $item->material->tracking_type,
                    'unit_of_measure' => $item->material->unit_of_measure,
                    'quantity_precision' => \App\Models\UnitOfMeasure::precisionForCode($item->material->unit_of_measure),
                    'output_quantity_precision' => $template->productType->quantity_precision,
                    'quantity_per_unit' => (float) $item->quantity_per_unit,
                    'component_quantity' => $item->component_quantity !== null ? (float) $item->component_quantity : null,
                    'output_quantity' => $item->output_quantity !== null ? (float) $item->output_quantity : null,
                    'scrap_percentage' => (float) $item->scrap_percentage,
                    'rounding_mode' => $item->rounding_mode,
                    'rounding_multiple' => (float) $item->rounding_multiple,
                    'consumed_at' => $item->consumed_at,
                    'step_number' => $item->template_step_id
                        ? $effectiveNumbersByStepId->get($item->template_step_id)
                        : null,
                    'external_code' => $item->material->external_code,
                    'external_system' => $item->material->external_system,
                ];
            })->toArray(),
            'snapshot_created_at' => now()->toIso8601String(),
        ];
    }
}
