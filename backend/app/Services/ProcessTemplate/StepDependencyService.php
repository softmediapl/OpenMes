<?php

namespace App\Services\ProcessTemplate;

use App\Models\ProcessTemplate;
use App\Models\TemplateStepDependency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StepDependencyService
{
    /**
     * Replace the process graph atomically after validating ownership and DAG integrity.
     *
     * @param  list<array{predecessor_step_id: int, successor_step_id: int, lag_minutes?: int}>  $dependencies
     */
    public function replace(ProcessTemplate $template, string $mode, array $dependencies): void
    {
        if (! in_array($mode, ['sequential', 'explicit'], true)) {
            throw ValidationException::withMessages(['dependency_mode' => __('Invalid process dependency mode.')]);
        }

        if ($mode === 'sequential' && $dependencies !== []) {
            throw ValidationException::withMessages([
                'dependencies' => __('Explicit dependencies are only allowed in explicit graph mode.'),
            ]);
        }

        $steps = $template->steps()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->validateGraph($steps, $dependencies);

        DB::transaction(function () use ($template, $mode, $dependencies) {
            $template->dependencies()->delete();
            $template->update(['dependency_mode' => $mode]);

            if ($mode !== 'explicit') {
                return;
            }

            foreach ($dependencies as $dependency) {
                $template->dependencies()->create([
                    'predecessor_step_id' => $dependency['predecessor_step_id'],
                    'successor_step_id' => $dependency['successor_step_id'],
                    'dependency_type' => TemplateStepDependency::TYPE_FINISH_TO_START,
                    'lag_minutes' => (int) ($dependency['lag_minutes'] ?? 0),
                ]);
            }
        });
    }

    /**
     * @param  list<int>  $stepIds
     * @param  list<array{predecessor_step_id: int, successor_step_id: int, lag_minutes?: int}>  $dependencies
     */
    private function validateGraph(array $stepIds, array $dependencies): void
    {
        $known = array_fill_keys($stepIds, true);
        $adjacency = array_fill_keys($stepIds, []);
        $inDegree = array_fill_keys($stepIds, 0);
        $seen = [];

        foreach ($dependencies as $index => $dependency) {
            $predecessor = (int) $dependency['predecessor_step_id'];
            $successor = (int) $dependency['successor_step_id'];
            $key = $predecessor.':'.$successor;

            if (! isset($known[$predecessor]) || ! isset($known[$successor])) {
                throw ValidationException::withMessages([
                    "dependencies.{$index}" => __('Every dependency must reference steps from this process template.'),
                ]);
            }
            if ($predecessor === $successor) {
                throw ValidationException::withMessages([
                    "dependencies.{$index}" => __('A process step cannot depend on itself.'),
                ]);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "dependencies.{$index}" => __('This process dependency is duplicated.'),
                ]);
            }

            $seen[$key] = true;
            $adjacency[$predecessor][] = $successor;
            $inDegree[$successor]++;
        }

        $queue = array_keys(array_filter($inDegree, fn (int $degree) => $degree === 0));
        $visited = 0;

        while ($queue !== []) {
            $step = array_shift($queue);
            $visited++;
            foreach ($adjacency[$step] as $successor) {
                $inDegree[$successor]--;
                if ($inDegree[$successor] === 0) {
                    $queue[] = $successor;
                }
            }
        }

        if ($visited !== count($stepIds)) {
            throw ValidationException::withMessages([
                'dependencies' => __('Process dependencies must not contain a cycle.'),
            ]);
        }
    }
}
