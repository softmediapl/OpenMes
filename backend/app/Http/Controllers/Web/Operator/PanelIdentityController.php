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
        $length = app(\App\Services\Operator\PanelCredentialService::class)->length();
        $usernameRequired = $operators->mode() !== 'pin_only';
        $data = $request->validate([
            'username' => [$usernameRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'pin' => ['required', $operators->mode() === 'pin_only' ? 'digits:'.$length : 'digits_between:4,12'],
        ]);

        if (! $operators->authenticate($request, $data['username'] ?? null, $data['pin'])) {
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
