<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Changing your own password. Asked for the current one, except the first time
 * after an administrator set it: then the person has only just typed it to sign in.
 */
class PasswordController extends Controller
{
    public function edit(Request $request)
    {
        return view('auth.password', ['forced' => (bool) $request->user()->must_change_password]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $forced = (bool) $user->must_change_password;

        $data = $request->validate(array_filter([
            'current_password' => $forced ? null : ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]));

        if (! $forced && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('That is not your current password.')]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => __('Choose a password different from the one you have now.')]);
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        AuditLog::log('user.password_changed', User::class, $user->id);

        return redirect()->to(url('/'))->with('success', __('Your password is changed.'));
    }
}
