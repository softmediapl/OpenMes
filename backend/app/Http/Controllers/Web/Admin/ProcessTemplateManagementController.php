<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\RevisionLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\StoreTemplateStepRequest;
use App\Http\Requests\Web\Admin\UpdateTemplateStepDependenciesRequest;
use App\Http\Requests\Web\Admin\UpdateTemplateStepRequest;
use App\Http\Requests\Web\Admin\UpsertProcessTemplateRequest;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateStepExclusion;
use App\Models\ProductRevision;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\Workstation;
use App\Services\ProcessTemplate\StepDependencyService;
use App\Services\ProcessTemplate\CompositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ProcessTemplateManagementController extends Controller
{
    public function __construct(private CompositionService $composition) {}

    /**
     * Display process templates for a product type
     */
    public function index(Request $request, ProductType $productType)
    {
        $revisionId = $request->query('revision_id');
        $templates = $productType->processTemplates()
            ->with('productRevision:id,revision_code,lifecycle_status')
            ->withCount('steps')
            ->when($revisionId, fn ($query) => $query->where('product_revision_id', $revisionId))
            ->orderBy('version', 'desc')
            ->get();

        return Inertia::render('admin/process-templates/Index', [
            'productType' => $productType->only('id', 'name', 'unit_of_measure', 'quantity_precision'),
            'activeRevisions' => $this->activeRevisionOptions($productType),
            'revisionFilter' => $revisionId ? (string) $revisionId : '',
            'templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'version' => $t->version,
                'is_active' => (bool) $t->is_active,
                'steps_count' => $t->steps_count,
                'product_revision' => $t->productRevision ? [
                    'id' => $t->productRevision->id,
                    'revision_code' => $t->productRevision->revision_code,
                    'lifecycle_status' => $t->productRevision->lifecycle_status?->value,
                ] : null,
                'created_at' => $t->created_at->format('Y-m-d H:i'),
            ]),
        ]);
    }

    /**
     * Show the form for creating a new process template
     */
    public function create(ProductType $productType)
    {
        return Inertia::render('admin/process-templates/Create', [
            'productType' => $productType->only('id', 'name', 'unit_of_measure', 'quantity_precision'),
            'revisions' => $this->assignableRevisionOptions($productType),
            'baseTemplates' => $this->baseTemplateOptions($productType),
        ]);
    }

    /**
     * Store a newly created process template
     */
    public function store(UpsertProcessTemplateRequest $request, ProductType $productType)
    {
        $validated = $request->validated();

        // Get the next version number
        $latestVersion = $productType->processTemplates()->max('version') ?? 0;
        $validated['version'] = $latestVersion + 1;
        $validated['product_type_id'] = $productType->id;
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['allow_partial_final_batch'] = $request->boolean('allow_partial_final_batch', true);

        $template = ProcessTemplate::create($validated);

        return redirect()->route('admin.product-types.process-templates.show', [$productType, $template])
            ->with('success', __('Process template created successfully. Now add production steps.'));
    }

    /**
     * Display the specified process template
     */
    public function show(ProductType $productType, ProcessTemplate $processTemplate)
    {
        // Ensure template belongs to this product type
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $processTemplate->load([
            'steps' => fn ($q) => $q->orderBy('step_number', 'asc'),
            'steps.workstation.line',
            'steps.processSegment',
            'steps.transportUnitType',
            'steps.qualityCheckTemplate',
            'photos.uploadedBy',
            'stepMedia',
            'checklistItems',
            'dependencies',
            'baseTemplate:id,name,version,product_type_id,base_template_id',
            'stepExclusions',
            'derivedTemplates:id,base_template_id',
        ]);
        $resolvedSteps = $this->composition->resolveSteps($processTemplate);
        $workstations = Workstation::active()->with('line')->orderBy('name')->get();
        $processSegments = \App\Models\ProcessSegment::query()
            ->active()
            ->orderBy('segment_type')
            ->orderBy('code')
            ->get();

        return Inertia::render('admin/process-templates/Show', [
            'productType' => $processTemplate->productType->only('id', 'name'),
            'processTemplate' => [
                'id' => $processTemplate->id,
                'name' => $processTemplate->name,
                'version' => $processTemplate->version,
                'base_template' => $processTemplate->baseTemplate ? [
                    'id' => $processTemplate->baseTemplate->id,
                    'name' => $processTemplate->baseTemplate->name,
                    'version' => $processTemplate->baseTemplate->version,
                ] : null,
                'excluded_operation_codes' => $processTemplate->stepExclusions->pluck('operation_code')->values(),
                'is_active' => (bool) $processTemplate->is_active,
                'is_locked_as_base' => $processTemplate->derivedTemplates->isNotEmpty(),
                'batch_policy' => $processTemplate->batchPolicySnapshot(),
                'packaging_policy' => $processTemplate->packagingPolicySnapshot(),
                'dependency_mode' => $processTemplate->dependency_mode,
                'dependencies' => $processTemplate->dependencies->map(fn ($dependency) => [
                    'predecessor_step_id' => $dependency->predecessor_step_id,
                    'successor_step_id' => $dependency->successor_step_id,
                    'lag_minutes' => $dependency->lag_minutes,
                ])->values(),
                'steps' => $resolvedSteps->map(function (array $resolved) {
                    $s = $resolved['step'];

                    return [
                    'id' => $s->id,
                    'step_number' => $resolved['step_number'],
                    'operation_code' => $s->operation_code,
                    'insert_after_operation_code' => $s->insert_after_operation_code,
                    'composition_source' => $resolved['source'],
                    'source_template_id' => $resolved['source_template_id'],
                    'name' => $s->name,
                    'instruction' => $s->instruction,
                    'requires_confirmation' => (bool) $s->requires_confirmation,
                    'quantity_reporting_required' => (bool) $s->quantity_reporting_required,
                    'requires_palletization' => (bool) $s->requires_palletization,
                    'estimated_duration_minutes' => $s->estimated_duration_minutes,
                    'execution_mode' => $s->execution_mode->value,
                    'labor_mode' => $s->labor_mode?->value,
                    'effective_labor_mode' => $s->effectiveLaborMode()->value,
                    'min_duration_minutes' => $s->min_duration_minutes,
                    'setup_time_minutes' => $s->setup_time_minutes,
                    'run_time_per_unit_minutes' => $s->run_time_per_unit_minutes,
                    'workstation_id' => $s->workstation_id,
                    'workstation_type_id' => $s->workstation_type_id,
                    'transport_unit_type_id' => $s->transport_unit_type_id,
                    'quality_check_template_id' => $s->quality_check_template_id,
                    'quality_gate_required' => (bool) $s->quality_gate_required,
                    'process_segment_id' => $s->process_segment_id,
                    'is_optional' => (bool) $s->is_optional,
                    'variant_group' => $s->variant_group,
                    'is_default_variant' => (bool) $s->is_default_variant,
                    'workstation' => $s->workstation ? [
                        'id' => $s->workstation->id,
                        'name' => $s->workstation->name,
                        'line_name' => $s->workstation->line?->name,
                    ] : null,
                    'process_segment' => $s->processSegment ? [
                        'id' => $s->processSegment->id,
                        'code' => $s->processSegment->code,
                    ] : null,
                    'transport_unit_type' => $s->transportUnitType ? [
                        'id' => $s->transportUnitType->id,
                        'code' => $s->transportUnitType->code,
                        'name' => $s->transportUnitType->name,
                    ] : null,
                    'quality_check_template' => $s->qualityCheckTemplate ? [
                        'id' => $s->qualityCheckTemplate->id,
                        'name' => $s->qualityCheckTemplate->name,
                    ] : null,
                    ];
                }),
                'photos' => $processTemplate->photos->map(fn ($p) => [
                    'id' => $p->id,
                    'template_step_id' => $p->template_step_id,
                    'url' => route('process-templates.photos.show', [$processTemplate, $p]),
                    'original_name' => $p->original_name,
                    'caption' => $p->caption,
                    'width' => $p->width,
                    'height' => $p->height,
                    'file_size' => $p->file_size_human ?? null,
                    'uploaded_by' => $p->uploadedBy?->name,
                    'created_at' => $p->created_at->format('Y-m-d H:i'),
                ]),
                'media' => $processTemplate->stepMedia->map(fn ($m) => [
                    'id' => $m->id,
                    'template_step_id' => $m->template_step_id,
                    'media_type' => $m->media_type,
                    'title' => $m->title,
                    'original_name' => $m->original_name,
                    'url' => route('process-templates.media.show', [$processTemplate, $m]),
                ]),
                'checklist_items' => $processTemplate->checklistItems->map(fn ($c) => [
                    'id' => $c->id,
                    'template_step_id' => $c->template_step_id,
                    'label' => $c->label,
                    'is_required' => (bool) $c->is_required,
                ]),
            ],
            'workstations' => $workstations->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'line_name' => $w->line?->name,
            ]),
            // ISA-95 Equipment Classes (#52) for the step's workstation-type picker.
            'workstationTypes' => \App\Models\WorkstationType::query()->active()->orderBy('name')->get(['id', 'name']),
            'transportUnitTypes' => \App\Models\TransportUnitType::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'default_capacity_quantity', 'unit_of_measure']),
            'qualityCheckTemplates' => $processTemplate->qualityCheckTemplates()
                ->orderBy('name')
                ->get(['id', 'name', 'min_checks_per_batch', 'samples_per_check']),
            'processSegments' => $processSegments->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'segment_type' => $s->segment_type,
                'instruction' => $s->standard_instruction,
                'duration' => $s->estimated_duration_minutes,
                'labor_mode' => $s->labor_mode->value,
            ]),
        ]);
    }

    /**
     * Show the form for editing a process template
     */
    public function edit(ProductType $productType, ProcessTemplate $processTemplate)
    {
        // Ensure template belongs to this product type
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        return Inertia::render('admin/process-templates/Edit', [
            'productType' => $productType->only('id', 'name', 'unit_of_measure', 'quantity_precision'),
            'revisions' => $this->assignableRevisionOptions($productType),
            'baseTemplates' => $this->baseTemplateOptions($productType, $processTemplate),
            'processTemplate' => [
                'id' => $processTemplate->id,
                'name' => $processTemplate->name,
                'version' => $processTemplate->version,
                'product_revision_id' => $processTemplate->product_revision_id,
                'base_template_id' => $processTemplate->base_template_id,
                'is_active' => (bool) $processTemplate->is_active,
                'preferred_batch_quantity' => $processTemplate->preferred_batch_quantity,
                'min_batch_quantity' => $processTemplate->min_batch_quantity,
                'max_batch_quantity' => $processTemplate->max_batch_quantity,
                'batch_quantity_multiple' => $processTemplate->batch_quantity_multiple,
                'allow_partial_final_batch' => (bool) $processTemplate->allow_partial_final_batch,
                'pallet_capacity_quantity' => $processTemplate->pallet_capacity_quantity,
                'is_locked_as_base' => $processTemplate->isLockedAsCompositionBase(),
            ],
        ]);
    }

    /**
     * Update the specified process template
     */
    public function update(UpsertProcessTemplateRequest $request, ProductType $productType, ProcessTemplate $processTemplate)
    {
        // Ensure template belongs to this product type
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        $validated = $request->validated();

        $validated['is_active'] = $request->boolean('is_active');
        $validated['allow_partial_final_batch'] = $request->boolean('allow_partial_final_batch', true);

        $processTemplate->update($validated);

        return redirect()->route('admin.product-types.process-templates.index', $productType)
            ->with('success', 'Process template updated successfully.');
    }

    public function copy(ProductType $productType, ProcessTemplate $processTemplate)
    {
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $copy = DB::transaction(function () use ($productType, $processTemplate) {
            $processTemplate->load([
                'qualityCheckTemplates',
                'steps',
                'bomItems',
                'checklistItems',
                'dependencies',
                'stepExclusions',
            ]);

            $latestVersion = $productType->processTemplates()->max('version') ?? 0;
            $newTemplate = $processTemplate->replicate([
                'version',
                'created_at',
                'updated_at',
                'deleted_at',
                'deleted_by_id',
            ]);
            $newTemplate->name = $processTemplate->name.' copy';
            $newTemplate->version = $latestVersion + 1;
            $newTemplate->save();

            $qualityMap = [];
            foreach ($processTemplate->qualityCheckTemplates as $qualityTemplate) {
                $newQuality = $qualityTemplate->replicate(['created_at', 'updated_at', 'deleted_at', 'deleted_by_id']);
                $newQuality->process_template_id = $newTemplate->id;
                $newQuality->save();
                $qualityMap[$qualityTemplate->id] = $newQuality->id;
            }

            $stepMap = [];
            foreach ($processTemplate->steps as $step) {
                $newStep = $step->replicate(['created_at', 'updated_at', 'deleted_at', 'deleted_by_id']);
                $newStep->process_template_id = $newTemplate->id;
                if ($newStep->quality_check_template_id && isset($qualityMap[$newStep->quality_check_template_id])) {
                    $newStep->quality_check_template_id = $qualityMap[$newStep->quality_check_template_id];
                }
                $newStep->save();
                $stepMap[$step->id] = $newStep->id;
            }

            foreach ($processTemplate->bomItems as $item) {
                $newItem = $item->replicate(['created_at', 'updated_at', 'deleted_at', 'deleted_by_id']);
                $newItem->process_template_id = $newTemplate->id;
                $newItem->template_step_id = $item->template_step_id ? ($stepMap[$item->template_step_id] ?? $item->template_step_id) : null;
                $newItem->save();
            }

            foreach ($processTemplate->checklistItems as $item) {
                $newItem = $item->replicate(['created_at', 'updated_at', 'deleted_at', 'deleted_by_id']);
                $newItem->process_template_id = $newTemplate->id;
                $newItem->template_step_id = $item->template_step_id ? ($stepMap[$item->template_step_id] ?? $item->template_step_id) : null;
                $newItem->save();
            }

            foreach ($processTemplate->dependencies as $dependency) {
                if (! isset($stepMap[$dependency->predecessor_step_id], $stepMap[$dependency->successor_step_id])) {
                    continue;
                }

                $newDependency = $dependency->replicate(['created_at', 'updated_at']);
                $newDependency->process_template_id = $newTemplate->id;
                $newDependency->predecessor_step_id = $stepMap[$dependency->predecessor_step_id];
                $newDependency->successor_step_id = $stepMap[$dependency->successor_step_id];
                $newDependency->save();
            }

            foreach ($processTemplate->stepExclusions as $exclusion) {
                $newExclusion = $exclusion->replicate(['created_at', 'updated_at']);
                $newExclusion->process_template_id = $newTemplate->id;
                $newExclusion->save();
            }

            return $newTemplate;
        });

        return redirect()->route('admin.product-types.process-templates.edit', [$productType, $copy])
            ->with('success', __('Process template copied. Review the variant before using it in production.'));
    }

    /**
     * Remove the specified process template
     */
    public function destroy(ProductType $productType, ProcessTemplate $processTemplate)
    {
        // Ensure template belongs to this product type
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        if ($processTemplate->derivedTemplates()->exists()) {
            return back()->with('error', __('Cannot delete a process version used as a composition base.'));
        }

        // Check if template has steps
        if ($processTemplate->steps()->count() > 0) {
            return redirect()->route('admin.product-types.process-templates.index', $productType)
                ->with('error', 'Cannot delete process template with existing steps. Deactivate it instead.');
        }

        $processTemplate->delete();

        return redirect()->route('admin.product-types.process-templates.index', $productType)
            ->with('success', 'Process template deleted successfully.');
    }

    /**
     * Toggle process template active status
     */
    public function toggleActive(ProductType $productType, ProcessTemplate $processTemplate)
    {
        // Ensure template belongs to this product type
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $processTemplate->update(['is_active' => ! $processTemplate->is_active]);

        $status = $processTemplate->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.product-types.process-templates.index', $productType)
            ->with('success', "Process template {$status} successfully.");
    }

    /**
     * Add a step to the process template
     */
    public function addStep(StoreTemplateStepRequest $request, ProductType $productType, ProcessTemplate $processTemplate)
    {
        // Ensure template belongs to this product type
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        $validated = $this->stepPayload($request);

        if (blank($validated['operation_code'] ?? null)) {
            $validated['operation_code'] = $this->nextOperationCode($processTemplate, $validated['name']);
        }

        // Get the next step number
        $maxStepNumber = $processTemplate->steps()->max('step_number') ?? 0;
        $validated['step_number'] = $maxStepNumber + 1;
        $validated['process_template_id'] = $processTemplate->id;

        TemplateStep::create($validated);

        return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
            ->with('success', 'Step added successfully.');
    }

    /**
     * Update a step in the process template
     */
    public function updateStep(UpdateTemplateStepRequest $request, ProductType $productType, ProcessTemplate $processTemplate, TemplateStep $step)
    {
        // Ensure template belongs to this product type and step belongs to template
        if ($processTemplate->product_type_id !== $productType->id || $step->process_template_id !== $processTemplate->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        $step->update($this->stepPayload($request));

        return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
            ->with('success', 'Step updated successfully.');
    }

    public function updateDependencies(
        UpdateTemplateStepDependenciesRequest $request,
        ProductType $productType,
        ProcessTemplate $processTemplate,
        StepDependencyService $dependencies,
    ) {
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        $dependencies->replace(
            $processTemplate,
            $request->validated('dependency_mode'),
            $request->validated('dependencies'),
        );

        return back()->with('success', __('Process dependencies updated successfully.'));
    }

    /**
     * Build the validated step payload: coerce the booleans and drop the
     * default-variant flag when the step isn't part of a variant group.
     *
     * @return array<string, mixed>
     */
    private function stepPayload(Request $request): array
    {
        $data = $request->validated();
        $data['requires_confirmation'] = $request->boolean('requires_confirmation');
        $data['quantity_reporting_required'] = $request->boolean('quantity_reporting_required');
        $data['requires_palletization'] = $request->boolean('requires_palletization');
        $data['quality_gate_required'] = $request->boolean('quality_gate_required');
        $data['quality_check_template_id'] = $data['quality_gate_required']
            ? ($data['quality_check_template_id'] ?? null)
            : null;
        $data['is_optional'] = $request->boolean('is_optional');
        $data['variant_group'] = $request->filled('variant_group') ? $request->input('variant_group') : null;
        $data['is_default_variant'] = $data['variant_group'] !== null && $request->boolean('is_default_variant');

        return $data;
    }

    public function overrideInheritedStep(
        ProductType $productType,
        ProcessTemplate $processTemplate,
        TemplateStep $baseStep,
    ) {
        $this->assertInheritedStep($productType, $processTemplate, $baseStep);

        $local = DB::transaction(function () use ($processTemplate, $baseStep) {
            $existing = $processTemplate->steps()->where('operation_code', $baseStep->operation_code)->first();
            if ($existing) {
                return $existing;
            }

            $baseStep->load(['photos', 'media', 'checklistItems']);
            $local = $baseStep->replicate(['created_at', 'updated_at', 'deleted_at', 'deleted_by_id']);
            $local->process_template_id = $processTemplate->id;
            $local->step_number = ($processTemplate->steps()->max('step_number') ?? 0) + 1;
            $local->insert_after_operation_code = null;
            $local->save();

            foreach (['photos', 'media', 'checklistItems'] as $relation) {
                foreach ($baseStep->{$relation} as $resource) {
                    $copy = $resource->replicate(['created_at', 'updated_at', 'deleted_at', 'deleted_by_id']);
                    $copy->process_template_id = $processTemplate->id;
                    $copy->template_step_id = $local->id;
                    if (in_array($relation, ['photos', 'media'], true)) {
                        $extension = pathinfo($resource->storage_path, PATHINFO_EXTENSION);
                        $directory = $relation === 'photos' ? 'process-template-photos' : 'template-step-media';
                        $copy->storage_path = $directory.'/'.$processTemplate->id.'/'.Str::random(40).'.'.$extension;
                        Storage::copy($resource->storage_path, $copy->storage_path);
                    }
                    $copy->save();
                }
            }

            return $local;
        });

        return back()->with('success', __('Inherited step is ready to customize.'));
    }

    public function omitInheritedStep(ProductType $productType, ProcessTemplate $processTemplate, TemplateStep $baseStep)
    {
        $this->assertInheritedStep($productType, $processTemplate, $baseStep);

        ProcessTemplateStepExclusion::firstOrCreate([
            'process_template_id' => $processTemplate->id,
            'operation_code' => $baseStep->operation_code,
        ]);

        return back()->with('success', __('Inherited step omitted from this variant.'));
    }

    public function restoreInheritedStep(ProductType $productType, ProcessTemplate $processTemplate, string $operationCode)
    {
        if ($processTemplate->product_type_id !== $productType->id || ! $processTemplate->baseTemplate?->steps()->where('operation_code', $operationCode)->exists()) {
            abort(404);
        }

        $processTemplate->stepExclusions()->where('operation_code', $operationCode)->delete();

        return back()->with('success', __('Inherited step restored.'));
    }

    private function assertInheritedStep(ProductType $productType, ProcessTemplate $processTemplate, TemplateStep $baseStep): void
    {
        if ($processTemplate->product_type_id !== $productType->id
            || $processTemplate->base_template_id === null
            || $baseStep->process_template_id !== $processTemplate->base_template_id) {
            abort(404);
        }
    }

    private function nextOperationCode(ProcessTemplate $template, string $name): string
    {
        $base = Str::upper(Str::slug($name, '_')) ?: 'OPERATION';
        $candidate = Str::limit($base, 70, '');
        $suffix = 1;

        while ($template->steps()->where('operation_code', $candidate)->exists()) {
            $candidate = Str::limit($base, 70, '').'_'.++$suffix;
        }

        return $candidate;
    }

    private function activeRevisionOptions(ProductType $productType)
    {
        return ProductRevision::where('product_type_id', $productType->id)
            ->where('lifecycle_status', RevisionLifecycle::Released->value)
            ->orderBy('revision_code')
            ->get(['id', 'revision_code', 'lifecycle_status'])
            ->map(fn ($revision) => [
                'id' => $revision->id,
                'revision_code' => $revision->revision_code,
                'lifecycle_status' => $revision->lifecycle_status?->value,
            ]);
    }

    private function assignableRevisionOptions(ProductType $productType)
    {
        return ProductRevision::where('product_type_id', $productType->id)
            ->whereIn('lifecycle_status', [
                RevisionLifecycle::Draft->value,
                RevisionLifecycle::Released->value,
            ])
            ->orderBy('revision_code')
            ->get(['id', 'revision_code', 'lifecycle_status'])
            ->map(fn ($revision) => [
                'id' => $revision->id,
                'revision_code' => $revision->revision_code,
                'lifecycle_status' => $revision->lifecycle_status?->value,
            ]);
    }

    private function baseTemplateOptions(ProductType $productType, ?ProcessTemplate $current = null)
    {
        return $productType->processTemplates()
            ->whereNull('base_template_id')
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->orderByDesc('version')
            ->get(['id', 'name', 'version'])
            ->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'version' => $template->version,
            ]);
    }

    /**
     * Delete a step from the process template
     */
    public function deleteStep(ProductType $productType, ProcessTemplate $processTemplate, TemplateStep $step)
    {
        // Ensure template belongs to this product type and step belongs to template
        if ($processTemplate->product_type_id !== $productType->id || $step->process_template_id !== $processTemplate->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        DB::transaction(function () use ($processTemplate, $step) {
            $stepNumber = $step->step_number;

            $processTemplate->dependencies()
                ->where(fn ($query) => $query
                    ->where('predecessor_step_id', $step->id)
                    ->orWhere('successor_step_id', $step->id))
                ->delete();
            $step->delete();

            // Renumber remaining steps after the graph no longer references the
            // removed operation. Snapshot dependencies use these stable numbers.
            DB::table('template_steps')
                ->where('process_template_id', $processTemplate->id)
                ->where('step_number', '>', $stepNumber)
                ->decrement('step_number');
        });

        return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
            ->with('success', 'Step deleted successfully.');
    }

    /**
     * Reorder steps via drag and drop (expects JSON body: {order: [id, id, ...]})
     */
    public function reorderSteps(Request $request, ProductType $productType, ProcessTemplate $processTemplate)
    {
        if ($processTemplate->product_type_id !== $productType->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        $validated = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'integer',
        ]);

        $stepIds = $validated['order'];

        // Verify every submitted ID belongs to this template
        $validCount = DB::table('template_steps')
            ->where('process_template_id', $processTemplate->id)
            ->whereIn('id', $stepIds)
            ->count();

        if ($validCount !== count($stepIds)) {
            return response()->json(['error' => 'Invalid step IDs'], 422);
        }

        // Use large offset first to avoid unique(process_template_id, step_number) violations
        DB::transaction(function () use ($stepIds) {
            $offset = 10000;
            foreach ($stepIds as $i => $id) {
                DB::table('template_steps')->where('id', $id)->update(['step_number' => $offset + $i + 1]);
            }
            foreach ($stepIds as $i => $id) {
                DB::table('template_steps')->where('id', $id)->update(['step_number' => $i + 1]);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Move a step up in the order
     */
    public function moveStepUp(ProductType $productType, ProcessTemplate $processTemplate, TemplateStep $step)
    {
        // Ensure template belongs to this product type and step belongs to template
        if ($processTemplate->product_type_id !== $productType->id || $step->process_template_id !== $processTemplate->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        if ($step->step_number <= 1) {
            return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
                ->with('error', 'Step is already first.');
        }

        // Swap with previous step
        $previousStep = $processTemplate->steps()
            ->where('step_number', $step->step_number - 1)
            ->first();

        if ($previousStep) {
            $origStep = $step->step_number;
            $origPrevious = $previousStep->step_number;
            DB::table('template_steps')->where('id', $step->id)->update(['step_number' => -1]);
            DB::table('template_steps')->where('id', $previousStep->id)->update(['step_number' => $origStep]);
            DB::table('template_steps')->where('id', $step->id)->update(['step_number' => $origPrevious]);
        }

        return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
            ->with('success', 'Step moved up successfully.');
    }

    /**
     * Move a step down in the order
     */
    public function moveStepDown(ProductType $productType, ProcessTemplate $processTemplate, TemplateStep $step)
    {
        // Ensure template belongs to this product type and step belongs to template
        if ($processTemplate->product_type_id !== $productType->id || $step->process_template_id !== $processTemplate->id) {
            abort(404);
        }

        $processTemplate->ensureMutable();

        $maxStepNumber = $processTemplate->steps()->max('step_number');
        if ($step->step_number >= $maxStepNumber) {
            return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
                ->with('error', 'Step is already last.');
        }

        // Swap with next step
        $nextStep = $processTemplate->steps()
            ->where('step_number', $step->step_number + 1)
            ->first();

        if ($nextStep) {
            $origStep = $step->step_number;
            $origNext = $nextStep->step_number;
            DB::table('template_steps')->where('id', $step->id)->update(['step_number' => -1]);
            DB::table('template_steps')->where('id', $nextStep->id)->update(['step_number' => $origStep]);
            DB::table('template_steps')->where('id', $step->id)->update(['step_number' => $origNext]);
        }

        return redirect()->route('admin.product-types.process-templates.show', [$productType, $processTemplate])
            ->with('success', 'Step moved down successfully.');
    }
}
