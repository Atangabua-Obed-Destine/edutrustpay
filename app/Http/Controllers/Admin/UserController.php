<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Body;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
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
                ->accessible()
                ->with('body', 'roles', 'institution')
                ->when($request->filled('body'), fn ($q) => $q->where('body_id', $request->integer('body')))
                ->orderBy('name')
                ->get(),
            'bodies' => $this->availableBodies(),
            'filters' => ['body' => $request->query('body')],
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.users.form', [
            'user' => new User([
                'body_id' => $request->integer('body') ?: (BodyContext::isSuperAdmin() ? null : BodyContext::current()),
                'is_active' => true,
            ]),
            'bodies' => $this->availableBodies(),
            'roles' => $this->assignableRoles($request),
            'selectedRoles' => [],
            'institutions' => Institution::query()->with('body')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        // The usual way: the account exists at once, with a first password the
        // administrator sets, and the person chooses their own when they sign in.
        if ($request->input('how', 'link') === 'password') {
            $request->validate(['password' => ['required', 'string', 'min:10', 'confirmed']]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'body_id' => $data['body_id'],
                'institution_id' => $data['institution_id'] ?? null,
                'role' => $data['role'],
                'is_active' => true,
                'password' => $request->input('password'),
                'invite_accepted_at' => now(),
            ]);
            $user->forceFill(['must_change_password' => $request->boolean('must_change', true)])->save();
            $user->roles()->sync($data['role_ids']);

            AuditLog::log('user.created', User::class, $user->id, null, [
                'email' => $user->email,
                'body_id' => $user->body_id,
                'role' => $user->role,
            ]);

            return redirect()
                ->to(manage_route('users.index', ['body' => $user->body_id]))
                ->with('success', $user->must_change_password
                    ? __(':name can sign in now with :email and the password you set. They will choose their own the first time.', ['name' => $user->name, 'email' => $user->email])
                    : __(':name can sign in now with :email and the password you set.', ['name' => $user->name, 'email' => $user->email]));
        }

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

        $user->roles()->sync($data['role_ids']);

        AuditLog::log('user.invited', User::class, $user->id, null, [
            'email' => $user->email,
            'body_id' => $user->body_id,
            'role' => $user->role,
        ]);

        return redirect()
            ->to(manage_route('users.index', ['body' => $user->body_id]))
            ->with('invite', [
                'name' => $user->name,
                'url' => route('invite.show', $user->invite_token),
                'expires' => now()->addDays(self::INVITE_DAYS)->toFormattedDateString(),
            ])
            ->with('success', __(':name invited.', ['name' => $user->name]));
    }

    /**
     * Set a new password for someone, e.g. one who has forgotten theirs. Unless
     * told otherwise, they choose their own the next time they sign in.
     */
    public function password(Request $request, User $user)
    {
        $this->guardTarget($request, $user);

        return view('admin.users.password', ['user' => $user]);
    }

    public function setPassword(Request $request, User $user)
    {
        $this->guardTarget($request, $user);

        $request->validate(['password' => ['required', 'string', 'min:10', 'confirmed']]);

        $user->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => $request->boolean('must_change', true),
            // A pending invitation is replaced by the password: the account works now.
            'invite_accepted_at' => $user->invite_accepted_at ?? now(),
            'invite_token' => null,
        ])->save();

        AuditLog::log('user.password_set', User::class, $user->id);

        return redirect()->to(manage_route('users.index'))
            ->with('success', __('New password set for :name. Give it to them in person or by phone.', ['name' => $user->name]));
    }

    /**
     * Issue a fresh invitation, invalidating any earlier one.
     */
    public function reinvite(Request $request, User $user)
    {
        $this->guardTarget($request, $user);

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
        $this->guardTarget($request, $user);

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
     * Change what somebody may do. A person may hold several roles.
     */
    public function edit(Request $request, User $user)
    {
        $this->guardTarget($request, $user);

        return view('admin.users.roles', [
            'user' => $user->load('roles', 'body'),
            'roles' => $this->assignableRoles($request, $user->body),
            'selectedRoles' => $user->roles->pluck('id')->all(),
            'institutions' => Institution::query()->with('body')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->guardTarget($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', __('You cannot change your own roles. Ask another administrator.'));
        }

        $request->merge(['name' => $user->name, 'body_id' => $user->body_id]);

        $data = $this->validated($request, $user);

        $before = $user->roles()->pluck('name')->all();

        $user->forceFill(['role' => $data['role'], 'institution_id' => $data['institution_id']])->save();
        $user->roles()->sync($data['role_ids']);
        $user->forgetPermissions();

        AuditLog::log('user.roles_changed', User::class, $user->id, ['roles' => $before], [
            'roles' => $user->roles()->pluck('name')->all(),
        ], $user->body_id);

        return redirect()->to(manage_route('users.index'))
            ->with('success', __(':name updated.', ['name' => $user->name]));
    }

    private function availableBodies()
    {
        return Body::whereIn('id', BodyContext::accessibleIds())->orderBy('path')->get();
    }

    /**
     * Roles this person may hand out: never one granting more than they hold.
     *
     * Without this, anyone who can invite people could invite themselves a second
     * account with every permission.
     */
    private function assignableRoles(Request $request, ?Body $forBody = null)
    {
        return Role::query()->usable()->with('permissions:id,name')->orderBy('display_name')->get()
            ->filter(fn (Role $role) => $role->grantsNoMoreThan($request->user()))
            ->filter(fn (Role $role) => ! $forBody || $role->availableTo($forBody))
            ->values();
    }

    /**
     * Refuse to act on an account outside this person's reach or above their rank.
     *
     * Route binding on User is not tenancy scoped, so without this an id in the
     * URL would reach another diocese's staff. 404 rather than 403, as elsewhere:
     * a 403 confirms the account exists.
     */
    private function guardTarget(Request $request, User $target): void
    {
        abort_unless(User::query()->accessible()->whereKey($target->id)->exists(), 404);

        $actor = $request->user();

        if ($actor->isSuperAdmin()) {
            return;
        }

        abort_if($target->isSuperAdmin(), 404);

        abort_if(array_diff($target->permissionNames(), $actor->permissionNames()) !== [], 403,
            'That person holds permissions you do not, so you cannot change their account.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => array_filter([$existing ? 'nullable' : 'required', 'email', 'max:191', $existing ? null : Rule::unique('users', 'email')]),
            'body_id' => ['required', Rule::in(BodyContext::accessibleIds())],
            'role_id' => ['required_without:role_ids', 'nullable', 'exists:roles,id'],
            'role_ids' => ['required_without:role_id', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'institution_id' => ['nullable', 'exists:institutions,id'],
        ], [], ['body_id' => __('body'), 'role_id' => __('role'), 'role_ids' => __('role'), 'institution_id' => __('institution')]);

        $data['role_ids'] = array_values(array_unique(array_map('intval', array_filter(
            array_merge($data['role_ids'] ?? [], [$data['role_id'] ?? null])
        ))));

        $body = Body::find($data['body_id']);
        $roles = Role::with('permissions:id,name')->whereIn('id', $data['role_ids'])->get();

        foreach ($roles as $candidate) {
            if (! $candidate->grantsNoMoreThan($request->user()) || ! $candidate->availableTo($body)) {
                throw ValidationException::withMessages([
                    'role_ids' => __('You cannot give somebody the role :role.', ['role' => $candidate->display_name]),
                ]);
            }
        }

        // users.role keeps the first role, for the coarse checks and for display.
        $role = $roles->firstWhere('name', 'institution_liaison') ?? $roles->first();
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
