<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Granular authorisation: `permission:finding.view|finding.resolve`.
 *
 * Several names separated by `|` mean "any one of these is enough". Names may
 * also be passed as separate parameters (`permission:a,b`), which behaves the
 * same way. Ported from edutrustshsm so the two codebases read alike.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized access.');
        }

        $names = collect($permissions)
            ->flatMap(fn (string $p) => explode('|', $p))
            ->map(fn (string $p) => trim($p))
            ->filter()
            ->all();

        if ($names === []) {
            return $next($request);
        }

        foreach ($names as $name) {
            if ($user->can($name)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
