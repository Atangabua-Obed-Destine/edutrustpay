<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone signed in with a password an administrator set for them chooses
 * their own before anything else, so no account is used with a password
 * somebody else knows. Signing out always stays possible.
 */
class EnsurePasswordChosen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs('account.password', 'account.password.update', 'logout')) {
            return redirect()->route('account.password');
        }

        return $next($request);
    }
}
