<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\BodyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session login for the console.
 *
 * Hand-rolled rather than Breeze, matching edutrustshsm: the brief specifies a
 * custom RBAC and no scaffolding, and an auth flow that everyone can read in one
 * file is worth more here than one nobody has looked at.
 */
class LoginController extends Controller
{
    public function show(\App\Services\PlatformInstaller $installer)
    {
        // Nobody can sign in yet: the console still has to create its first account.
        if (! $installer->isInstalled()) {
            return redirect()->route('install.show');
        }

        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // One message for a wrong password and an unknown address alike:
            // saying which is which tells an attacker who has an account here.
            throw ValidationException::withMessages([
                'email' => __('Those credentials do not match our records.'),
            ]);
        }

        if (! $request->user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __('This account is not active. Ask your body administrator.'),
            ]);
        }

        $request->session()->regenerate();

        // The body context is per-request and memoized; a stale memo from before
        // login would scope this session to the wrong body.
        BodyContext::flush();

        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditLog::log('auth.login', \App\Models\User::class, $request->user()->id);

        return redirect()->intended($request->user()->isSuperAdmin() ? route('admin.dashboard') : route('body.dashboard'));
    }

    public function destroy(Request $request)
    {
        AuditLog::log('auth.logout', \App\Models\User::class, $request->user()?->id);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        BodyContext::flush();

        return redirect()->route('login');
    }
}
