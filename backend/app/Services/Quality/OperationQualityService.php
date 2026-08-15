<?php

namespace App\Services\Quality;

use App\Models\BatchStep;
use App\Models\Issue;
use App\Models\IssueType;
use App\Models\QualityCheck;
use App\Models\User;
use App\Services\IssueService;
use App\Services\Production\QualityCheckService;
use Illuminate\Support\Facades\DB;

class OperationQualityService
{
    public function __construct(
        private QualityCheckService $qualityCheckService,
        private IssueService $issueService,
    ) {}

    /**
     * Fail before production starts when a released operation carries an
     * incomplete quality-gate snapshot. Released orders must not depend on a
     * mutable template that may subsequently be changed or removed.
     */
    public function guardCanStart(BatchStep $step): void
    {
        if (! $step->quality_gate_required) {
            return;
        }

        $specification = $step->quality_check_specification;
        if (! is_array($specification)
            || empty($specification['name'])
            || empty($specification['required_checks'])
            || empty($specification['samples_per_check'])) {
            throw new \DomainException(__('This operation has an invalid quality-gate configuration. Release a new work-order revision after correcting the process template.'));
        }
    }

    /**
     * A required operation gate is closed until the configured number of
     * passing checks has been recorded and every blocking failure is resolved.
     */
    public function guardCanComplete(BatchStep $step): void
    {
        if (! $step->quality_gate_required) {
            return;
        }

        $this->guardCanStart($step);

        $requiredChecks = max(1, (int) data_get($step->quality_check_specification, 'required_checks', 1));
        $passingChecks = $step->qualityChecks()->where('all_passed', true)->count();

        if ($passingChecks < $requiredChecks) {
            throw new \DomainException(__(
                'This operation is blocked: :remaining passing quality check(s) are still required.',
                ['remaining' => $requiredChecks - $passingChecks],
            ));
        }

        $hasOpenBlockingFailure = $step->issues()
            ->open()
            ->whereHas('issueType', fn ($query) => $query->where('is_blocking', true))
            ->exists();

        if ($hasOpenBlockingFailure) {
            throw new \DomainException(__('This operation is blocked by an unresolved quality non-conformance.'));
        }
    }

    /**
     * Record one operation-scoped check against the immutable specification
     * copied into the released batch step.
     *
     * @param  array<int, array<string, mixed>>  $samples
     */
    public function performCheck(
        BatchStep $step,
        User $user,
        array $samples,
        ?float $productionQuantity = null,
        ?string $notes = null,
    ): QualityCheck {
        return DB::transaction(function () use ($step, $user, $samples, $productionQuantity, $notes) {
            $step = BatchStep::query()->lockForUpdate()->findOrFail($step->getKey());
            $this->guardCanStart($step);

            if (! $step->quality_gate_required) {
                throw new \DomainException(__('This operation does not require a quality check.'));
            }

            if ($step->status !== BatchStep::STATUS_IN_PROGRESS) {
                throw new \DomainException(__('Start this operation before recording its quality check.'));
            }

            $normalizedSamples = $this->normalizeSamples($step, $samples);
            $template = $step->qualityCheckTemplate;
            $check = $this->qualityCheckService->performCheck(
                $step->batch,
                $user,
                $normalizedSamples,
                $productionQuantity ?? (float) ($step->input_quantity ?? $step->expectedInputQuantity()),
                $template,
                $notes,
                null,
                $step,
            );

            if (! $check->all_passed) {
                $issue = $this->raiseFailureIssue($step, $check, $user);
                $check->update(['issue_id' => $issue->id]);
            }

            return $check->fresh(['samples', 'issue']);
        });
    }

    /** @return array<string, mixed> */
    public function status(BatchStep $step): array
    {
        if (! $step->quality_gate_required) {
            return ['required' => false, 'fulfilled' => true];
        }

        $requiredChecks = max(1, (int) data_get($step->quality_check_specification, 'required_checks', 1));
        $checks = $step->relationLoaded('qualityChecks')
            ? $step->qualityChecks->sortBy('checked_at')->values()
            : $step->qualityChecks()
                ->with(['samples', 'checkedBy', 'issue.issueType'])
                ->orderBy('checked_at')
                ->get();
        $passingChecks = $checks->where('all_passed', true)->count();
        $openBlockingFailures = $checks->contains(fn (QualityCheck $check) => $check->issue?->isBlocking() ?? false);

        return [
            'required' => true,
            'fulfilled' => $passingChecks >= $requiredChecks && ! $openBlockingFailures,
            'required_checks' => $requiredChecks,
            'passing_checks' => $passingChecks,
            'remaining_checks' => max(0, $requiredChecks - $passingChecks),
            'has_open_blocking_failure' => $openBlockingFailures,
            'specification' => $step->quality_check_specification,
            'checks' => $checks,
        ];
    }

    /**
     * Validate names, sample numbers, types and limits on the server. The client
     * renders the form, but it is not trusted to decide whether a value passed.
     *
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSamples(BatchStep $step, array $samples): array
    {
        $specification = $step->quality_check_specification;
        $parameters = collect($specification['parameters'] ?? []);
        if ($parameters->isEmpty()) {
            $parameters = collect([['name' => __('Result'), 'type' => 'pass_fail']]);
        }

        $samplesPerCheck = max(1, (int) ($specification['samples_per_check'] ?? 1));
        $provided = collect($samples);
        $expectedCount = $samplesPerCheck * $parameters->count();
        if ($provided->count() !== $expectedCount) {
            throw new \DomainException(__(
                'The quality check requires exactly :count result(s).',
                ['count' => $expectedCount],
            ));
        }

        $normalized = [];
        foreach (range(1, $samplesPerCheck) as $sampleNumber) {
            foreach ($parameters as $parameter) {
                $name = trim((string) ($parameter['name'] ?? ''));
                $type = ($parameter['type'] ?? 'pass_fail') === 'measurement' ? 'measurement' : 'pass_fail';
                $matches = $provided->filter(fn ($sample) => (int) ($sample['sample_number'] ?? 0) === $sampleNumber
                    && trim((string) ($sample['parameter_name'] ?? '')) === $name
                );

                if ($matches->count() !== 1) {
                    throw new \DomainException(__(
                        'Provide one result for ":parameter" in sample :sample.',
                        ['parameter' => $name, 'sample' => $sampleNumber],
                    ));
                }

                $sample = $matches->first();
                if (($sample['parameter_type'] ?? null) !== $type) {
                    throw new \DomainException(__('A quality-check parameter type does not match the released specification.'));
                }

                $normalized[] = $type === 'measurement'
                    ? $this->normalizeMeasurement($sampleNumber, $name, $parameter, $sample)
                    : $this->normalizePassFail($sampleNumber, $name, $sample);
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeMeasurement(int $sampleNumber, string $name, array $parameter, array $sample): array
    {
        if (! is_numeric($sample['value_numeric'] ?? null)) {
            throw new \DomainException(__('Enter a numeric value for ":parameter".', ['parameter' => $name]));
        }

        $value = (float) $sample['value_numeric'];
        $hasMinimum = isset($parameter['min']) && is_numeric($parameter['min']);
        $hasMaximum = isset($parameter['max']) && is_numeric($parameter['max']);
        $passed = (! $hasMinimum || $value >= (float) $parameter['min'])
            && (! $hasMaximum || $value <= (float) $parameter['max']);

        if (! $hasMinimum && ! $hasMaximum) {
            if (! array_key_exists('is_passed', $sample)) {
                throw new \DomainException(__('Select a result for ":parameter".', ['parameter' => $name]));
            }
            $passed = filter_var($sample['is_passed'], FILTER_VALIDATE_BOOL);
        }

        return [
            'sample_number' => $sampleNumber,
            'parameter_name' => $name,
            'parameter_type' => 'measurement',
            'value_numeric' => $value,
            'is_passed' => $passed,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePassFail(int $sampleNumber, string $name, array $sample): array
    {
        if (! array_key_exists('is_passed', $sample)) {
            throw new \DomainException(__('Select a result for ":parameter".', ['parameter' => $name]));
        }

        return [
            'sample_number' => $sampleNumber,
            'parameter_name' => $name,
            'parameter_type' => 'pass_fail',
            'value_boolean' => filter_var($sample['is_passed'], FILTER_VALIDATE_BOOL),
            'is_passed' => filter_var($sample['is_passed'], FILTER_VALIDATE_BOOL),
        ];
    }

    private function raiseFailureIssue(BatchStep $step, QualityCheck $check, User $user): Issue
    {
        $issueType = IssueType::query()
            ->active()
            ->where('code', 'IN_PROCESS_QC_FAIL')
            ->where('is_blocking', true)
            ->first()
            ?? IssueType::query()->active()->blocking()->orderBy('id')->first();

        if (! $issueType) {
            throw new \DomainException(__('A blocking in-process quality issue type must be configured before recording a failed gate.'));
        }

        return $this->issueService->createIssue([
            'work_order_id' => $step->batch->work_order_id,
            'batch_step_id' => $step->id,
            'issue_type_id' => $issueType->id,
            'source' => Issue::SOURCE_IN_PROCESS,
            'title' => __('Operation quality gate failed: :operation', ['operation' => $step->name]),
            'description' => __('Quality check #:check failed against the released specification.', ['check' => $check->id]),
            'reported_by_id' => $user->id,
        ]);
    }
}
