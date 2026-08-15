<?php

namespace App\Services\Operator;

use App\Models\BatchStep;
use App\Models\PanelSupervisorAuthorization;
use App\Models\User;
use App\Models\Workstation;
use App\Support\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PanelSupervisorAuthorizationService
{
    public function __construct(
        private readonly PanelCredentialService $credentials,
        private readonly PanelOperatorContext $operators,
    ) {}

    public function mode(Workstation $workstation): string
    {
        $mode = $workstation->panel_supervisor_mode ?: SystemSetting::get('panel_supervisor_mode', 'inline_pin');

        return in_array($mode, ['inline_pin', 'session_takeover', 'remote_only'], true) ? $mode : 'inline_pin';
    }

    public function authenticateSupervisor(Request $request, ?string $username, ?string $pin, string $mode): ?User
    {
        if ($mode === 'session_takeover') {
            $actor = $request->user();

            return $actor?->hasAnyRole(['Supervisor', 'Admin']) ? $actor : null;
        }

        if ($mode !== 'inline_pin' || ! $pin) {
            return null;
        }

        $tenantId = $request->user()?->tenant_id;
        $supervisor = $this->operators->mode() === 'pin_only'
            ? $this->credentials->find($pin, $tenantId)
            : User::query()->where('tenant_id', $tenantId)->where('username', $username)->where('account_type', 'user')->first();

        return $supervisor
            && $supervisor->hasAnyRole(['Supervisor', 'Admin'])
            && ! empty($supervisor->pin)
            && Hash::check($pin, $supervisor->pin)
                ? $supervisor
                : null;
    }

    public function grant(Request $request, Workstation $workstation, BatchStep $step, User $supervisor, string $action, string $reason): PanelSupervisorAuthorization
    {
        $operator = $request->attributes->get('panel_operator') ?? $request->user();

        return $this->grantFor($workstation, $step, $operator, $supervisor, $action, $reason, $this->mode($workstation));
    }

    public function grantFor(Workstation $workstation, BatchStep $step, User $operator, User $supervisor, string $action, string $reason, string $mode): PanelSupervisorAuthorization
    {
        return PanelSupervisorAuthorization::create([
            'tenant_id' => $operator->tenant_id,
            'workstation_id' => $workstation->id,
            'batch_step_id' => $step->id,
            'operator_id' => $operator->id,
            'supervisor_id' => $supervisor->id,
            'action' => $action,
            'mode' => $mode,
            'reason' => $reason,
            'authorized_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function active(Request $request, BatchStep $step, string $action): ?PanelSupervisorAuthorization
    {
        $workstation = $request->attributes->get(WorkstationContext::REQUEST_ATTRIBUTE);
        if (! $workstation) {
            return null;
        }

        return PanelSupervisorAuthorization::query()
            ->where('workstation_id', $workstation->id)
            ->where('batch_step_id', $step->id)
            ->where('operator_id', $request->user()->id)
            ->where('action', $action)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('authorized_at')
            ->first();
    }

    public function consume(?PanelSupervisorAuthorization $authorization): void
    {
        $authorization?->update(['consumed_at' => now()]);
    }
}
