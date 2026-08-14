<?php

namespace App\Services\ProcessTemplate;

use App\Models\ProcessTemplate;

class SnapshotService
{
    /**
     * Create a JSONB snapshot of a process template.
     *
     * This snapshot is immutable and stored with the work order,
     * so changes to the template don't affect existing work orders.
     */
    public function createSnapshot(ProcessTemplate $template): array
    {
        $template->load([
            'steps' => function ($query) {
                $query->orderBy('step_number');
            },
            'steps.processSegment',
            'steps.media',
            'steps.photos',
            'steps.workstation',
            'dependencies.predecessor',
            'dependencies.successor',
            'bomItems.material.materialType',
            'bomItems.templateStep',
        ]);

        $stepsById = $template->steps->keyBy('id');
        $dependencyMode = $template->dependency_mode === 'explicit' ? 'explicit' : 'sequential';
        if ($dependencyMode === 'explicit') {
            $dependencies = $template->dependencies->map(function ($dependency) use ($stepsById) {
                $predecessor = $stepsById->get($dependency->predecessor_step_id);
                $successor = $stepsById->get($dependency->successor_step_id);

                return $predecessor && $successor ? [
                    'predecessor_step_number' => $predecessor->step_number,
                    'successor_step_number' => $successor->step_number,
                    'dependency_type' => $dependency->dependency_type,
                    'lag_minutes' => $dependency->lag_minutes,
                ] : null;
            })->filter()->values();
        } else {
            $orderedSteps = $template->steps->values();
            $dependencies = collect();
            for ($index = 1; $index < $orderedSteps->count(); $index++) {
                $dependencies->push([
                    'predecessor_step_number' => $orderedSteps[$index - 1]->step_number,
                    'successor_step_number' => $orderedSteps[$index]->step_number,
                    'dependency_type' => \App\Models\TemplateStepDependency::TYPE_FINISH_TO_START,
                    'lag_minutes' => 0,
                ]);
            }
        }

        return [
            'template_id' => $template->id,
            'template_name' => $template->name,
            'template_version' => $template->version,
            'product_type_id' => $template->product_type_id,
            'dependency_mode' => $dependencyMode,
            'dependencies' => $dependencies->toArray(),
            'steps' => $template->steps->map(function ($step) {
                return [
                    'step_number' => $step->step_number,
                    'name' => $step->name,
                    'instruction' => $step->effectiveInstruction(),
                    'requires_confirmation' => (bool) $step->requires_confirmation
                        && $step->hasConfirmableInstructionContent(),
                    'quantity_reporting_required' => (bool) $step->quantity_reporting_required,
                    'estimated_duration_minutes' => $step->estimated_duration_minutes,
                    'execution_mode' => $step->execution_mode->value,
                    'min_duration_minutes' => $step->min_duration_minutes,
                    'setup_time_minutes' => $step->setup_time_minutes,
                    'run_time_per_unit_minutes' => $step->run_time_per_unit_minutes,
                    'required_operators' => $step->effectiveRequiredOperators(),
                    'workstation_id' => $step->workstation_id,
                    'workstation_name' => $step->workstation?->name,
                    'workstation_type_id' => $step->effectiveWorkstationType(),
                    'transport_unit_type_id' => $step->transport_unit_type_id,
                    'is_optional' => (bool) $step->is_optional,
                    'variant_group' => $step->variant_group,
                    'is_default_variant' => (bool) $step->is_default_variant,
                ];
            })->toArray(),
            'bom' => $template->bomItems->map(function ($item) {
                return [
                    'material_id' => $item->material_id,
                    'material_code' => $item->material->code,
                    'material_name' => $item->material->name,
                    'material_type' => $item->material->materialType?->code,
                    'tracking_type' => $item->material->tracking_type,
                    'unit_of_measure' => $item->material->unit_of_measure,
                    'quantity_per_unit' => (float) $item->quantity_per_unit,
                    'scrap_percentage' => (float) $item->scrap_percentage,
                    'consumed_at' => $item->consumed_at,
                    'step_number' => $item->templateStep?->step_number,
                    'external_code' => $item->material->external_code,
                    'external_system' => $item->material->external_system,
                ];
            })->toArray(),
            'snapshot_created_at' => now()->toIso8601String(),
        ];
    }
}
