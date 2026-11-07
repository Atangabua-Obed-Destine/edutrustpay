<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Support\BodyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A body's own roles.
 *
 * Offices differ from one body to another, so a body decides what each person
 * may do rather than choosing from a fixed list. Two limits keep that safe: a
 * role can never grant more than the person creating it holds, and platform
 * roles can be copied but not changed.
 */
class RoleController extends Controller
{
    /** Decisions about the platform's bodies, which are not a body's to give out. */
    private const PLATFORM_ONLY = ['body.create', 'body.update', 'body.delete', 'settings.view', 'settings.update'];

    public function index()
    {
        return view('body.roles.index', [
            'roles' => Role::query()->usable()->with('permissions:id,name,display_name')->withCount('users')
                ->orderByDesc('is_system')->orderBy('display_name')->get(),
            'manageableIds' => Role::query()->manageable()->pluck('id')->all(),
        ]);
    }

    public function create(Request $request)
    {
        // "Copy" starts a new role from an existing one, usually a platform role.
        $from = $request->filled('from')
            ? Role::query()->usable()->with('permissions:id,name')->find($request->integer('from'))
            : null;

        return view('body.roles.form', [
            'role' => new Role([
                'display_name' => $from ? __(':name (copy)', ['name' => $from->display_name]) : null,
                'description' => $from?->description,
            ]),
            'selected' => $from ? $from->permissions->pluck('name')->all() : [],
            'groups' => $this->grantable($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $body = BodyContext::currentBody();

        $role = Role::create([
            'body_id' => $body->id,
            'name' => 'b'.$body->id.'_'.Str::slug($data['display_name'], '_').'_'.Str::lower(Str::random(4)),
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $this->syncPermissions($role, $data['permissions'] ?? []);

        return redirect()->route('body.manage.roles.index')
            ->with('success', __(':name created. Give it to people under People.', ['name' => $role->display_name]));
    }

    public function edit(Request $request, Role $role)
    {
        $this->guard($request, $role);

        return view('body.roles.form', [
            'role' => $role,
            'selected' => $role->permissions()->pluck('name')->all(),
            'groups' => $this->grantable($request),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->guard($request, $role);

        $data = $this->validated($request);

        $role->update([
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
        ]);

        $this->syncPermissions($role, $data['permissions'] ?? []);

        return redirect()->route('body.manage.roles.index')
            ->with('success', __(':name saved. The change applies to everyone holding it.', ['name' => $role->display_name]));
    }

    public function destroy(Request $request, Role $role)
    {
        $this->guard($request, $role);

        if ($role->users()->exists()) {
            return back()->with('error', __(':name is still held by people. Move them to another role first.', ['name' => $role->display_name]));
        }

        $role->delete();

        return redirect()->route('body.manage.roles.index')->with('success', __('Role deleted.'));
    }

    /**
     * Only this body's own roles, and none granting more than this person holds:
     * saving such a role would quietly strip what they cannot see, or let them
     * hand it out.
     */
    private function guard(Request $request, Role $role): void
    {
        abort_unless(Role::query()->manageable()->whereKey($role->id)->exists(), 404);

        abort_unless($role->load('permissions:id,name')->grantsNoMoreThan($request->user()), 403,
            'This role holds permissions you do not, so you cannot change it.');
    }

    /**
     * @return array{display_name: string, description?: ?string, permissions?: array<int, string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:191'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ], [], ['display_name' => __('name')]);

        $allowed = $this->grantable($request)->flatten(1)->pluck('name')->all();
        $refused = array_diff($data['permissions'] ?? [], $allowed);

        if ($refused !== []) {
            throw ValidationException::withMessages([
                'permissions' => __('You cannot grant: :list.', ['list' => implode(', ', $refused)]),
            ]);
        }

        return $data;
    }

    /**
     * What this person may put in a role: what they hold, minus platform decisions.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Permission>>
     */
    private function grantable(Request $request)
    {
        $user = $request->user();

        return Permission::query()->orderBy('group_name')->orderBy('id')->get()
            ->reject(fn (Permission $p) => in_array($p->name, self::PLATFORM_ONLY, true))
            ->filter(fn (Permission $p) => $user->isSuperAdmin() || in_array($p->name, $user->permissionNames(), true))
            ->groupBy('group_name');
    }

    /**
     * @param  array<int, string>  $names
     */
    private function syncPermissions(Role $role, array $names): void
    {
        $before = $role->permissions()->pluck('name')->all();

        $role->permissions()->sync(Permission::whereIn('name', $names)->pluck('id'));

        AuditLog::log('role.permissions_changed', Role::class, $role->id, ['permissions' => $before], [
            'permissions' => array_values($names),
        ], $role->body_id);
    }
}
