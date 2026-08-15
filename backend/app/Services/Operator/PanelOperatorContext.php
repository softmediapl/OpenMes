<?php

namespace App\Services\Operator;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PanelOperatorContext
{
    public const SESSION_KEY = 'panel_operator_id';

    public function operator(Request $request): ?User
    {
        $operatorId = $request->session()->get(self::SESSION_KEY);
        if (! $operatorId) {
            return null;
        }

        return User::query()
            ->whereKey($operatorId)
            ->where('account_type', 'user')
            ->where('tenant_id', $request->user()?->tenant_id)
            ->first();
    }

    public function authenticate(Request $request, string $username, string $pin): ?User
    {
        $operator = User::query()
            ->where('username', $username)
            ->where('account_type', 'user')
            ->where('tenant_id', $request->user()?->tenant_id)
            ->first();

        if (! $operator || empty($operator->pin) || ! Hash::check($pin, $operator->pin)
            || ! $operator->hasAnyRole(['Operator', 'Supervisor', 'Admin'])) {
            return null;
        }

        $request->session()->put(self::SESSION_KEY, $operator->id);

        return $operator;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
