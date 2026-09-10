<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse role gating: `role:super_admin,body_admin`.
 *
 * Kept alongside the finer `permission:` middleware as defence in depth, which
 * is how edutrustshsm uses it. The two surfaces here have genuinely different
 * audiences — /admin is the platform operator, /body is the body's own people —
 * so the coarse check is doing real work at the route-group level rather than
 * merely duplicating the permission check.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
