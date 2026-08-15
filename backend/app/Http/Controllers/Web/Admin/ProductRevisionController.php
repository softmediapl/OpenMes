<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\RevisionLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\StoreProductRevisionRequest;
use App\Http\Requests\Web\Admin\UpdateProductRevisionRequest;
use App\Models\ProductRevision;
use App\Models\ProductType;
use Inertia\Inertia;

class ProductRevisionController extends Controller
{
    /**
     * List revisions. Rows live-sync via the `product_revisions` shape; product
     * type names and cross-table counts come as props.
     */
    public function index()
    {
        $counts = ProductRevision::withCount(['workOrders', 'processTemplates'])
            ->get(['id'])
            ->mapWithKeys(fn ($r) => [$r->id => [
                'work_orders' => $r->work_orders_count,
                'process_templates' => $r->process_templates_count,
            ]]);

        return Inertia::render('admin/product-revisions/Index', [
            'productTypes' => ProductType::orderBy('name')->get(['id', 'code', 'name']),
            'counts' => $counts,
        ]);
    }

    /**
     * Detail page — primarily a host for the engineering-documents panel (#179),
     * so CAD files can be attached to a specific product revision.
     */
    public function show(ProductRevision $productRevision)
    {
        $productRevision->load([
            'productType:id,code,name',
            'processTemplates' => fn ($query) => $query
                ->select('id', 'product_revision_id', 'name', 'version', 'is_active')
                ->orderByDesc('is_active')
                ->orderByDesc('version'),
        ]);

        return Inertia::render('admin/product-revisions/Show', [
            'productRevision' => [
                'id' => $productRevision->id,
                'revision_code' => $productRevision->revision_code,
                'description' => $productRevision->description,
                'lifecycle_status' => $productRevision->lifecycle_status,
                'external_ref' => $productRevision->external_ref,
                'effective_from' => $productRevision->effective_from,
                'effective_to' => $productRevision->effective_to,
                'released_at' => $productRevision->released_at,
                'product_type' => $productRevision->productType?->only('id', 'code', 'name'),
                'process_templates' => $productRevision->processTemplates->map(fn ($template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'version' => $template->version,
                    'is_active' => (bool) $template->is_active,
                ])->values(),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/product-revisions/Create', $this->formOptions());
    }

    public function store(StoreProductRevisionRequest $request)
    {
        $validated = $request->validated();
        $validated['lifecycle_status'] = RevisionLifecycle::Draft;

        $revision = ProductRevision::create($validated);

        return redirect()->route('admin.product-revisions.index')
            ->with('success', __('Product revision :code created.', ['code' => $revision->revision_code]));
    }

    public function edit(ProductRevision $productRevision)
    {
        return Inertia::render('admin/product-revisions/Edit', array_merge($this->formOptions(), [
            'revision' => $productRevision->only(
                'id', 'product_type_id', 'revision_code', 'description',
                'change_reason', 'external_ref',
                'lifecycle_status', 'effective_from', 'effective_to'
            ),
        ]));
    }

    public function update(UpdateProductRevisionRequest $request, ProductRevision $productRevision)
    {
        // A released or obsolete revision is immutable — its configuration is
        // frozen so historical work orders stay consistent. Only DRAFT edits.
        if (! $productRevision->isDraft()) {
            return back()->with('error', __('Only draft revisions can be edited. Released revisions are immutable.'));
        }

        $productRevision->update($request->validated());

        return redirect()->route('admin.product-revisions.index')
            ->with('success', __('Product revision :code updated.', ['code' => $productRevision->revision_code]));
    }

    public function destroy(ProductRevision $productRevision)
    {
        // A revision already used by a work order is kept for traceability.
        if ($productRevision->workOrders()->exists()) {
            return back()->with('error', __('Cannot delete a revision that is used by work orders.'));
        }

        $productRevision->delete();

        return redirect()->route('admin.product-revisions.index')
            ->with('success', __('Product revision :code deleted.', ['code' => $productRevision->revision_code]));
    }

    /** DRAFT → RELEASED. Requires at least one active template assigned to it. */
    public function release(ProductRevision $productRevision)
    {
        if (! $productRevision->isDraft()) {
            return back()->with('error', __('Only draft revisions can be released.'));
        }

        if (! $productRevision->processTemplates()->where('is_active', true)->exists()) {
            return back()->with('error', __('Assign at least one active process template before releasing the revision.'));
        }

        $productRevision->update([
            'lifecycle_status' => RevisionLifecycle::Released,
            'released_at' => now(),
            'released_by_id' => auth()->id(),
        ]);

        return back()->with('success', __('Product revision :code released.', ['code' => $productRevision->revision_code]));
    }

    /** RELEASED → OBSOLETE. Kept for historical traceability, no longer selectable. */
    public function obsolete(ProductRevision $productRevision)
    {
        if (! $productRevision->isReleased()) {
            return back()->with('error', __('Only released revisions can be made obsolete.'));
        }

        $productRevision->update([
            'lifecycle_status' => RevisionLifecycle::Obsolete,
            'obsolete_at' => now(),
        ]);

        return back()->with('success', __('Product revision :code marked obsolete.', ['code' => $productRevision->revision_code]));
    }

    private function formOptions(): array
    {
        return [
            'productTypes' => ProductType::orderBy('name')->get(['id', 'code', 'name']),
        ];
    }
}
