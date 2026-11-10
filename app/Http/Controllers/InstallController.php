<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\PlatformInstaller;
use App\Support\BodyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The page behind the installer's one-time link: the operator account, and
 * the first organisation the console oversees.
 */
class InstallController extends Controller
{
    public function show(PlatformInstaller $installer, ?string $token = null)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('login');
        }

        return $installer->tokenIsValid($token)
            ? view('install.setup', ['token' => $token])
            : view('install.locked');
    }

    public function store(Request $request, PlatformInstaller $installer, string $token)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('login');
        }

        abort_unless($installer->tokenIsValid($token), 403);

        $validated = $request->validate([
            'organisation_name' => ['nullable', 'string', 'max:255'],
            'organisation_type' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user = $installer->finish(
            ['name' => $validated['name'], 'email' => $validated['email'], 'password' => $validated['password']],
            ['name' => $validated['organisation_name'] ?? null, 'type' => $validated['organisation_type'] ?? null],
        );

        Auth::login($user);
        $request->session()->regenerate();
        BodyContext::flush();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLog::log('auth.login', User::class, $user->id);

        $body = $installer->firstBody();

        return redirect()->route('admin.dashboard')->with('success', $body
            ? __(':platform is ready. Next, open :body under Bodies to invite its administrator and add its first institution.', ['platform' => platform_name(), 'body' => $body->name])
            : __(':platform is ready. Next, add the first organisation under Bodies, then invite its administrator.', ['platform' => platform_name()]));
    }
}
