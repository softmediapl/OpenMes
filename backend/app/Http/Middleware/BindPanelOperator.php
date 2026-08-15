<?php

namespace App\Http\Middleware;

use App\Services\Operator\PanelOperatorContext;
use App\Services\Operator\WorkstationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindPanelOperator
{
    public function __construct(
        private readonly WorkstationContext $workstations,
        private readonly PanelOperatorContext $operators,
    ) {}

    public function handle(Request $request, Closure $next, string $required = 'optional'): Response
    {
        // Resolve the device assignment before replacing the request actor.
        $this->workstations->bind($request);
        $operator = $this->operators->operator($request);

        if ($required === 'required' && ! $operator) {
            abort(403, __('Identify the operator with a PIN before continuing.'));
        }

        if ($operator) {
            $request->attributes->set('panel_operator', $operator);
            $request->setUserResolver(fn () => $operator);
        }

        return $next($request);
    }
}
