<?php

namespace App\Http\Middleware;

use App\Support\BodyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the body console for a body's own people.
 *
 * The operator belongs to no body. Letting that account open /body showed one
 * diocese's pages under the platform's name and menu, so nobody could tell which
 * side they were on. The operator now sees a body's console only by choosing
 * "View as this body", which says so in a banner and is read-only.
 */
class EnsureBodyView
{
    public function handle(Request $request, Closure $next): Response
    {
        if (BodyContext::isSuperAdmin()) {
            return redirect()->route('admin.bodies.index')->with('warning', __(
                'The body console belongs to each body\'s own people. To see what they see, choose View as this body.'
            ));
        }

        return $next($request);
    }
}
