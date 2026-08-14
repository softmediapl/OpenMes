<?php

namespace App\Http\Middleware;

use App\Services\Operator\WorkstationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindOperatorWorkstation
{
    public function __construct(private readonly WorkstationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->bind($request);

        return $next($request);
    }
}
