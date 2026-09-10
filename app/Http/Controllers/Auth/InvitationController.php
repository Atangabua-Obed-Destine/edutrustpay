<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Accepting an invitation.
 *
 * The person sets their own password; the platform never generates one, never
 * shows one and never mails one (brief §9). The token is single-use and is
 * destroyed the moment it is redeemed, so a link left in a chat history or an
 * inbox stops working as soon as the account is live.
 */
class InvitationController extends Controller
{
    public function show(string $token)
    {
        $user = $this->resolve($token);

        return view('auth.invite', ['user' => $user, 'token' => $token]);
    }

    public function store(Request $request, string $token)
    {
        $user = $this->resolve($token);

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user->forceFill([
            'password' => $request->input('password'),
            // Burn the token. A single-use link that keeps working is not
            // single-use, and these get forwarded.
            'invite_token' => null,
            'invite_accepted_at' => now(),
            'last_login_at' => now(),
        ])->save();

        AuditLog::log('user.invite_accepted', User::class, $user->id);

        Auth::login($user);
        $request->session()->regenerate();
        BodyContext::flush();

        return redirect()
            ->route($user->isSuperAdmin() ? 'admin.dashboard' : 'body.dashboard')
            ->with('success', __('Welcome, :name.', ['name' => $user->name]));
    }

    /**
     * Find the invited account, or refuse.
     *
     * An expired or already-used link gets the same 404 as a wrong one: telling
     * a stranger that a token was "already accepted" confirms the account
     * exists, and there is no reason to hand that out.
     */
    private function resolve(string $token): User
    {
        $user = User::where('invite_token', $token)->whereNull('invite_accepted_at')->first();

        abort_if(! $user, 404);

        abort_if(
            $user->invite_sent_at === null
            || $user->invite_sent_at->addDays(UserController::INVITE_DAYS)->isPast(),
            404
        );

        return $user;
    }
}
