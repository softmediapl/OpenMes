<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\StoreWorkstationMaterialPolicyRequest;
use App\Http\Requests\Web\Admin\UpdateWorkstationMaterialPolicyRequest;
use App\Models\WorkstationMaterialPolicy;

class WorkstationMaterialPolicyController extends Controller
{
    public function store(StoreWorkstationMaterialPolicyRequest $request)
    {
        WorkstationMaterialPolicy::create($request->validated());

        return back()->with('success', __('Workstation material policy created.'));
    }

    public function update(
        UpdateWorkstationMaterialPolicyRequest $request,
        WorkstationMaterialPolicy $workstationMaterialPolicy,
    ) {
        $workstationMaterialPolicy->update($request->validated());

        return back()->with('success', __('Workstation material policy updated.'));
    }

    public function destroy(WorkstationMaterialPolicy $workstationMaterialPolicy)
    {
        if ($workstationMaterialPolicy->requests()->open()->exists()) {
            return back()->with('error', __('Cancel or deliver open replenishment requests before deleting this policy.'));
        }

        $workstationMaterialPolicy->delete();

        return back()->with('success', __('Workstation material policy deleted.'));
    }
}
