<?php

namespace App\Services\ProcessTemplate;

use App\Models\ProcessTemplate;
use App\Models\TemplateStep;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CompositionService
{
    private const STEP_RELATIONS = [
        'processSegment',
        'media',
        'photos',
        'workstation',
        'transportUnitType',
        'qualityCheckTemplate',
    ];

    /**
     * Resolve the effective route without mutating the base or variant template.
     *
     * @return Collection<int, array{step: TemplateStep, step_number: int, source: string, source_template_id: int}>
     */
    public function resolveSteps(ProcessTemplate $template): Collection
    {
        $template->loadMissing(['steps' => fn ($query) => $query->with(self::STEP_RELATIONS)->orderBy('step_number')]);

        if ($template->base_template_id === null) {
            return $template->steps->values()->map(function (TemplateStep $step): array {
                $row = $this->resolvedRow($step, 'local');
                $row['step_number'] = (int) $step->step_number;

                return $row;
            });
        }

        $template->loadMissing(['baseTemplate.steps' => fn ($query) => $query->with(self::STEP_RELATIONS)->orderBy('step_number'), 'stepExclusions']);
        $base = $template->baseTemplate;

        if (! $base || $base->product_type_id !== $template->product_type_id) {
            throw new InvalidArgumentException('The base process template must belong to the same product type.');
        }
        if ($base->base_template_id !== null) {
            throw new InvalidArgumentException('Process template composition supports one inheritance level.');
        }

        $excluded = $template->stepExclusions->pluck('operation_code')->flip();
        $localByCode = $template->steps->keyBy('operation_code');
        $resolved = collect();

        foreach ($base->steps as $baseStep) {
            if ($excluded->has($baseStep->operation_code)) {
                continue;
            }

            $override = $localByCode->pull($baseStep->operation_code);
            $resolved->push($this->resolvedRow($override ?? $baseStep, $override ? 'override' : 'inherited'));
        }

        $lastInsertionIndexByAnchor = [];
        foreach ($localByCode->sortBy('step_number') as $localStep) {
            $row = $this->resolvedRow($localStep, 'local');
            $anchor = $localStep->insert_after_operation_code;
            $anchorIndex = $anchor && array_key_exists($anchor, $lastInsertionIndexByAnchor)
                ? $lastInsertionIndexByAnchor[$anchor]
                : ($anchor
                ? $resolved->search(fn (array $item) => $item['step']->operation_code === $anchor)
                : false);

            if ($anchorIndex === false) {
                $resolved->push($row);
            } else {
                $resolved->splice($anchorIndex + 1, 0, [$row]);
                $lastInsertionIndexByAnchor[$anchor] = $anchorIndex + 1;

                foreach ($lastInsertionIndexByAnchor as $knownAnchor => $knownIndex) {
                    if ($knownAnchor !== $anchor && $knownIndex > $anchorIndex) {
                        $lastInsertionIndexByAnchor[$knownAnchor]++;
                    }
                }
            }
        }

        return $this->number($resolved);
    }

    /** @return array{step: TemplateStep, source: string, source_template_id: int} */
    private function resolvedRow(TemplateStep $step, string $source): array
    {
        return [
            'step' => $step,
            'source' => $source,
            'source_template_id' => (int) $step->process_template_id,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $steps */
    private function number(Collection $steps): Collection
    {
        return $steps->values()->map(function (array $row, int $index): array {
            $row['step_number'] = $index + 1;

            return $row;
        });
    }
}
