<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Services\Operator\PanelOperatorContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PanelIdentityController extends Controller
{
    public function store(Request $request, PanelOperatorContext $operators)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'digits:6'],
        ]);

        if (! $operators->authenticate($request, $data['username'], $data['pin'])) {
            throw ValidationException::withMessages([
                'pin' => __('Invalid username or PIN.'),
            ]);
        }

        return back()->with('success', __('Operator identified.'));
    }

    public function destroy(Request $request, PanelOperatorContext $operators)
    {
        $operators->forget($request);

        return redirect()->route('panel.index');
    }
}
