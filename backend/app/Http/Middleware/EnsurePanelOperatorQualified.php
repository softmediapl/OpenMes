<?php

namespace App\Http\Middleware;

use App\Models\BatchStep;
use App\Services\Operator\PanelQualificationService;
use App\Models\PanelSupervisorAuthorization;
use App\Services\Operator\PanelSupervisorAuthorizationService;
use App\Services\Operator\WorkstationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelOperatorQualified
{
    public function __construct(
        private readonly WorkstationContext $workstations,
        private readonly PanelQualificationService $qualifications,
        private readonly PanelSupervisorAuthorizationService $authorizations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workstation = $this->workstations->workstation($request);
        $step = $request->route('batchStep');
        $result = $workstation
            ? $this->qualifications->evaluate($request->user(), $workstation, $step instanceof BatchStep ? $step : null)
            : ['qualified' => false, 'reasons' => [__('No workstation is assigned to this terminal.')]];

        if (! $result['qualified']) {
            $authorization = $step instanceof BatchStep
                ? $this->authorizations->active($request, $step, PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED)
                : null;
            if ($authorization) {
                $request->attributes->set('panel_supervisor_authorization', $authorization);

                return $next($request);
            }

            return back()->withErrors(['qualification' => implode(' ', $result['reasons'])])
                ->with('error', implode(' ', $result['reasons']));
        }

        return $next($request);
    }
}
