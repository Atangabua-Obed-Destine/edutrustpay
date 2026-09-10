<?php

namespace App\Http\Middleware;

use App\Support\BodyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the per-request body context memo at the start of every web request.
 *
 * Without this the memo leaks between requests under Octane, and between tests
 * and queued jobs sharing a process — which would show one body's figures to
 * another body's user. That is the one failure this platform genuinely cannot
 * have, so the flush is unconditional and runs before anything else in the web
 * group.
 */
class ResolveBodyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        BodyContext::flush();

        return $next($request);
    }
}
