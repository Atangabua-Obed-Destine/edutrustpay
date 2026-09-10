<?php

namespace App\Http\Middleware;

use App\Support\BodyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps impersonation honest: looking, never touching.
 *
 * The operator can view the platform as one body's own people see it. That is a
 * support tool — "the bursary head says the consolidated total looks wrong, show
 * me what they are looking at" — and support tools that can also WRITE are how
 * an action nobody performed ends up in the record. A finding answered from
 * behind this banner would carry the operator's user id and the body's context,
 * and six months later nobody would be able to say who decided it.
 *
 * So the rule is one sentence, which is why it is enforceable: while
 * impersonating, every request that is not a plain read is refused.
 *
 * Two exceptions, both necessary rather than convenient:
 *   - leaving impersonation, which is the way back out;
 *   - signing out, which must never be something a user cannot do.
 *
 * The operator surface is redirected rather than refused. Its pages would still
 * render — `role:super_admin` reads the account's role, which has not changed —
 * but they would render against a NARROWED context, showing one body's
 * institutions on a page whose whole purpose is to show every body's. A page
 * that lies quietly is worse than a page that sends you back.
 */
class HandleImpersonation
{
    /** Routes that stay available while impersonating. */
    private const ALWAYS_ALLOWED = ['impersonate.stop', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! BodyContext::isImpersonating()) {
            return $next($request);
        }

        // The body was deleted or deactivated out from under the session. Fail
        // back to being the operator rather than leaving them in a context that
        // resolves to nothing and renders as an empty, unexplained console.
        if (BodyContext::impersonatedBody() === null) {
            BodyContext::stopImpersonating();

            return redirect()->route('admin.bodies.index')
                ->with('warning', __('That body no longer exists, so the view was returned to the operator console.'));
        }

        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        if ($request->routeIs('admin.*')) {
            return redirect()->route('body.dashboard')->with('warning', __(
                'The operator console is unavailable while viewing as :body. Leave that view first.',
                ['body' => BodyContext::impersonatedBody()->name]
            ));
        }

        if (! $request->isMethodSafe()) {
            abort(403, __('This view is read-only. Leave it before making changes.'));
        }

        return $next($request);
    }
}
