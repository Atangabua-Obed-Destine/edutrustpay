<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Body;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Provisioning the body's own people.
 *
 * BY INVITATION LINK, NEVER A PASSWORD (brief §9). No password is generated, no
 * password is displayed, and none is emailed. The operator creates the account
 * and hands over a single-use link; the person sets their own password, and the
 * token is destroyed the moment they do.
 *
 * The link is shown on screen once rather than mailed, because mail is not
 * configured on a fresh install and an invitation that silently fails to send is
 * worse than one you have to copy by hand. Where mail exists, send it there too.
 */
class UserController extends Controller
{
    /** How long an unused invitation stays valid. */
    public const INVITE_DAYS = 14;

    public function index(Request $request)
    {
        return view('admin.users.index', [
            'users' => User::query()
                ->with('body', 'roles', 'institution')
                ->when($request->filled('body'), fn ($q) => $q->where('body_id', $request->integer('body')))
                ->orderBy('name')
                ->get(),
            'bodies' => Body::orderBy('path')->get(),
            'filters' => ['body' => $request->query('body')],
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.users.form', [
            'user' => new User(['body_id' => $request->integer('body') ?: null, 'is_active' => true]),
            'bodies' => Body::orderBy('path')->get(),
            'roles' => Role::orderBy('display_name')->get(),
            'institutions' => Institution::withoutBodyScope()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'body_id' => $data['body_id'],
            'institution_id' => $data['institution_id'] ?? null,
            'role' => $data['role'],
            'is_active' => true,
            // A random password nobody knows. The account cannot be used until
            // the invitation is accepted and a real one is set.
            'password' => Str::random(64),
            'invite_token' => hash('sha256', Str::random(64)),
            'invite_sent_at' => now(),
        ]);

        $user->roles()->sync([$data['role_id']]);

        AuditLog::log('user.invited', User::class, $user->id, null, [
            'email' => $user->email,
            'body_id' => $user->body_id,
            'role' => $user->role,
        ]);

        return redirect()
            ->route('admin.users.index', ['body' => $user->body_id])
            ->with('invite', [
                'name' => $user->name,
                'url' => route('invite.show', $user->invite_token),
                'expires' => now()->addDays(self::INVITE_DAYS)->toFormattedDateString(),
            ])
            ->with('success', __(':name invited.', ['name' => $user->name]));
    }

    /**
     * Issue a fresh invitation, invalidating any earlier one.
     */
    public function reinvite(User $user)
    {
        if ($user->invite_accepted_at) {
            return back()->with('error', __(':name has already accepted their invitation. Deactivate the account instead if they should no longer have access.', ['name' => $user->name]));
        }

        $user->forceFill([
            'invite_token' => hash('sha256', Str::random(64)),
            'invite_sent_at' => now(),
        ])->save();

        return back()->with('invite', [
            'name' => $user->name,
            'url' => route('invite.show', $user->invite_token),
            'expires' => now()->addDays(self::INVITE_DAYS)->toFormattedDateString(),
        ]);
    }

    /**
     * Deactivate or reactivate.
     *
     * Never deleted: a user who resolved a finding has their name against it,
     * and removing the row would leave that decision unattributed.
     */
    public function toggle(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', __('You cannot deactivate your own account.'));
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        AuditLog::log($user->is_active ? 'user.reactivated' : 'user.deactivated', User::class, $user->id);

        return back()->with('success', $user->is_active
            ? __(':name reactivated.', ['name' => $user->name])
            : __(':name deactivated and can no longer sign in.', ['name' => $user->name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'body_id' => ['required', 'exists:bodies,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'institution_id' => ['nullable', 'exists:institutions,id'],
        ], [], ['body_id' => __('body'), 'role_id' => __('role'), 'institution_id' => __('institution')]);

        $role = Role::find($data['role_id']);
        $data['role'] = $role->name;

        // A `nullable` field that was not submitted at all is absent from the
        // validated array, not null. Normalise once here so everything below can
        // read it without guarding.
        $data['institution_id'] = $data['institution_id'] ?? null;

        /*
         * An institution liaison is scoped to exactly one institution, so the
         * account is meaningless without one — and worse than meaningless: with
         * no institution set, the liaison narrowing in BodyScope does not apply
         * and they would quietly see the whole body.
         *
         * Thrown as a ValidationException so it lands as an inline field error.
         */
        if ($role->name === 'institution_liaison' && blank($data['institution_id'])) {
            throw ValidationException::withMessages([
                'institution_id' => __('An institution liaison must be tied to one institution, or they would see the whole body.'),
            ]);
        }

        /*
         * The institution must belong to the body the account is being created
         * in, or the liaison is scoped to something their body cannot see.
         */
        if ($role->name === 'institution_liaison') {
            $belongs = Institution::withoutBodyScope()
                ->where('id', $data['institution_id'])
                ->where('body_id', $data['body_id'])
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'institution_id' => __('That institution does not belong to the chosen body.'),
                ]);
            }
        }

        if ($role->name !== 'institution_liaison') {
            $data['institution_id'] = null;
        }

        return $data;
    }
}
