<?php

namespace App\Services\Operator;

use App\Models\User;
use App\Models\Shift;
use App\Support\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PanelOperatorContext
{
    public const SESSION_KEY = 'panel_operator_id';

    public function __construct(private readonly PanelCredentialService $credentials) {}

    public function mode(): string
    {
        $mode = SystemSetting::get('panel_identity_mode', 'username_pin');

        return in_array($mode, ['username_pin', 'pin_only', 'list_pin'], true) ? $mode : 'username_pin';
    }

    public function operator(Request $request): ?User
    {
        $operatorId = $request->session()->get(self::SESSION_KEY);
        if (! $operatorId) {
            return null;
        }

        $startedAt = (int) $request->session()->get('panel_operator_started_at', 0);
        $maxAge = max(1, min(24, SystemSetting::integer('panel_operator_session_hours', 12))) * 3600;
        if (! $startedAt || now()->timestamp - $startedAt > $maxAge) {
            $this->forget($request);

            return null;
        }

        $workstation = $request->attributes->get(WorkstationContext::REQUEST_ATTRIBUTE);
        $storedShiftId = $request->session()->get('panel_operator_shift_id');
        if ($storedShiftId && $workstation) {
            $currentShiftId = Shift::current($workstation->line_id)?->id;
            if ((int) $currentShiftId !== (int) $storedShiftId) {
                $this->forget($request);

                return null;
            }
        }

        $operator = User::query()
            ->whereKey($operatorId)
            ->where('account_type', 'user')
            ->where('tenant_id', $request->user()?->tenant_id)
            ->first();

        if (! $operator || ! $operator->hasAnyRole(['Operator', 'Supervisor', 'Admin'])) {
            $this->forget($request);

            return null;
        }

        return $operator;
    }

    public function authenticate(Request $request, ?string $username, string $pin): ?User
    {
        $tenantId = $request->user()?->tenant_id;
        $operator = $this->mode() === 'pin_only'
            ? $this->credentials->find($pin, $tenantId)
            : User::query()
                ->where('username', $username)
                ->where('account_type', 'user')
                ->where('tenant_id', $tenantId)
                ->first();

        if (! $operator || empty($operator->pin) || ! Hash::check($pin, $operator->pin)
            || ! $operator->hasAnyRole(['Operator', 'Supervisor', 'Admin'])) {
            return null;
        }

        $workstation = $request->attributes->get(WorkstationContext::REQUEST_ATTRIBUTE);
        $request->session()->put([
            self::SESSION_KEY => $operator->id,
            'panel_operator_started_at' => now()->timestamp,
            'panel_operator_shift_id' => $workstation ? Shift::current($workstation->line_id)?->id : null,
        ]);

        return $operator;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_KEY,
            'panel_operator_started_at',
            'panel_operator_shift_id',
        ]);
    }
}
