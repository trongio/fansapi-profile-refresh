<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The demo UI is deliberately local-only; there is no auth layer to review. */
class EnsureLocalDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            app()->environment('local') || in_array($request->ip(), ['127.0.0.1', '::1'], true),
            403,
            'The demo interface is only reachable locally.',
        );

        return $next($request);
    }
}
