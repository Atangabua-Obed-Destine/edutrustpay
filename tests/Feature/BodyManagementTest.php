<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A body runs its own schools, people and roles.
 *
 * What matters most here is what it must NOT be able to do: reach another
 * diocese, or hand out more than it holds. Each was tried through HTTP, because
 * the protection lives in routes, bindings and validation together.
 */
class BodyManagementTest extends TestCase
{
    use RefreshDatabase;

    private Body $bamenda;

    private Body $deanery;

    private Body $buea;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->bamenda = $this->makeBody('Diocese of Bamenda');
        $this->deanery = $this->makeBody('Central Deanery', $this->bamenda);
        $this->buea = $this->makeBody('Diocese of Buea');

        $this->admin = $this->person('admin@bamenda.test', 'body_admin', $this->bamenda);
    }

    // ------------------------------------------------------------- schools

    public function test_a_body_admin_can_add_a_school_to_their_own_body(): void
    {
        $this->as($this->admin)->post(route('body.manage.institutions.store'), $this->school($this->bamenda))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('institutions', ['name' => 'St Mary', 'body_id' => $this->bamenda->id]);
    }

    public function test_the_form_offers_each_school_level_and_health_apart_from_other_businesses(): void
    {
        $this->as($this->admin)->get(route('body.manage.institutions.create'))
            ->assertOk()
            ->assertSeeInOrder(['Schools', 'Nursery / Primary school', 'Secondary / High school', 'University / Higher institute',
                'Health', 'Hospital / Health centre / Pharmacy', 'Business', 'Shop / Other business']);

        foreach (\App\Support\InstitutionType::all() as $i => $type) {
            $this->as($this->admin)->post(route('body.manage.institutions.store'), ['name' => 'Place '.$i, 'type' => $type] + $this->school($this->bamenda))
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('institutions', ['name' => 'Place '.$i, 'type' => $type]);
        }

        // The old combined type cannot say which level a school is.
        $this->as($this->admin)->post(route('body.manage.institutions.store'), ['name' => 'Old', 'type' => 'school_basic'] + $this->school($this->bamenda))
            ->assertSessionHasErrors('type');
    }

    public function test_a_hospital_or_pharmacy_has_no_pupils_but_a_nursery_school_does(): void
    {
        $pharmacy = Institution::withoutBodyScope()->create(['body_id' => $this->bamenda->id, 'name' => 'Shisong Pharmacy', 'type' => 'health', 'system' => 'posinnova']);
        $nursery = Institution::withoutBodyScope()->create(['body_id' => $this->bamenda->id, 'name' => 'St Anne Nursery', 'type' => 'school_primary', 'system' => 'edutrustshsm']);

        $this->assertNotContains(\EdutrustPay\Contract\Capability::ENROLMENT, $pharmacy->applicableCapabilities());
        $this->assertContains(\EdutrustPay\Contract\Capability::ENROLMENT, $nursery->applicableCapabilities());
    }

    public function test_a_body_admin_can_add_a_school_to_a_body_inside_theirs(): void
    {
        $this->as($this->admin)->post(route('body.manage.institutions.store'), $this->school($this->deanery))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('institutions', ['name' => 'St Mary', 'body_id' => $this->deanery->id]);
    }

    public function test_a_body_admin_cannot_add_a_school_to_another_diocese(): void
    {
        $this->as($this->admin)->post(route('body.manage.institutions.store'), $this->school($this->buea))
            ->assertSessionHasErrors('body_id');

        $this->assertDatabaseMissing('institutions', ['name' => 'St Mary']);
    }

    public function test_another_dioceses_school_cannot_be_reached_or_changed(): void
    {
        $clinic = $this->institution($this->buea, 'Buea Clinic');
        $key = InstitutionCredential::issue($clinic, 'server')['credential'];

        $this->as($this->admin);

        $this->get(route('body.manage.institutions.show', $clinic))->assertNotFound();
        $this->get(route('body.manage.institutions.edit', $clinic))->assertNotFound();
        $this->put(route('body.manage.institutions.update', $clinic), $this->school($this->bamenda))->assertNotFound();
        $this->post(route('body.manage.credentials.store', $clinic))->assertNotFound();
        $this->post(route('body.manage.credentials.rotate', [$clinic, $key]))->assertNotFound();
        $this->post(route('body.manage.credentials.revoke', [$clinic, $key]), ['reason' => 'mine now'])->assertNotFound();

        $this->assertNull($key->fresh()->rotated_at);
        $this->assertNull($key->fresh()->revoked_at);
    }

    public function test_a_school_cannot_be_moved_into_another_diocese(): void
    {
        $school = $this->institution($this->bamenda, 'St Mary');

        $this->as($this->admin)->put(route('body.manage.institutions.update', $school), $this->school($this->buea))
            ->assertSessionHasErrors('body_id');

        $this->assertSame($this->bamenda->id, $school->fresh()->body_id);
    }

    public function test_a_body_admin_can_issue_a_key_and_sees_it_once(): void
    {
        $school = $this->institution($this->bamenda, 'St Mary');

        $this->as($this->admin)->post(route('body.manage.credentials.store', $school))
            ->assertRedirect(route('body.manage.institutions.show', $school))
            ->assertSessionHas('secret');
    }

    public function test_an_auditor_can_read_but_change_nothing(): void
    {
        $school = $this->institution($this->bamenda, 'St Mary');
        $auditor = $this->person('auditor@bamenda.test', 'auditor', $this->bamenda);

        $this->as($auditor);

        $this->get(route('body.institutions.index'))->assertOk()->assertDontSee('Add an institution');
        $this->get(route('body.manage.institutions.create'))->assertForbidden();
        $this->post(route('body.manage.institutions.store'), $this->school($this->bamenda))->assertForbidden();
        $this->post(route('body.manage.credentials.store', $school))->assertForbidden();
    }

    // -------------------------------------------------------------- people

    public function test_a_body_admin_can_invite_somebody_with_several_roles(): void
    {
        $secretary = Role::where('name', 'education_secretary')->first();
        $auditor = Role::where('name', 'auditor')->first();

        $this->as($this->admin)->post(route('body.manage.users.store'), [
            'name' => 'Fr Secretary',
            'email' => 'secretary@bamenda.test',
            'body_id' => $this->bamenda->id,
            'role_ids' => [$secretary->id, $auditor->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('invite');

        $user = User::where('email', 'secretary@bamenda.test')->firstOrFail();

        $this->assertCount(2, $user->roles);
        $this->assertTrue($user->can('finding.resolve'), 'From Education Secretary.');
        $this->assertTrue($user->can('user.view'), 'From Auditor.');
    }

    public function test_a_body_admin_cannot_invite_into_another_diocese(): void
    {
        $this->as($this->admin)->post(route('body.manage.users.store'), [
            'name' => 'Spy',
            'email' => 'spy@buea.test',
            'body_id' => $this->buea->id,
            'role_ids' => [Role::where('name', 'auditor')->first()->id],
        ])->assertSessionHasErrors('body_id');

        $this->assertDatabaseMissing('users', ['email' => 'spy@buea.test']);
    }

    public function test_another_dioceses_people_are_invisible_and_untouchable(): void
    {
        $other = $this->person('bursar@buea.test', 'bursary_head', $this->buea);

        $this->as($this->admin);

        $this->get(route('body.manage.users.index'))->assertOk()->assertDontSee('bursar@buea.test');
        $this->post(route('body.manage.users.toggle', $other))->assertNotFound();
        $this->post(route('body.manage.users.reinvite', $other))->assertNotFound();
        $this->get(route('body.manage.users.edit', $other))->assertNotFound();
        $this->put(route('body.manage.users.update', $other), ['role_ids' => [1]])->assertNotFound();

        $this->assertTrue((bool) $other->fresh()->is_active);
    }

    public function test_the_operator_account_cannot_be_touched_from_a_body(): void
    {
        $operator = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'password', 'role' => 'super_admin']);

        $this->as($this->admin)->post(route('body.manage.users.toggle', $operator))->assertNotFound();

        $this->assertTrue((bool) $operator->fresh()->is_active);
    }

    public function test_nobody_can_hand_out_more_than_they_hold(): void
    {
        $limited = Role::create(['body_id' => $this->bamenda->id, 'name' => 'b_hr', 'display_name' => 'HR Clerk']);
        $limited->permissions()->sync(Permission::whereIn('name', ['user.view', 'user.invite', 'user.update'])->pluck('id'));

        $clerk = $this->person('clerk@bamenda.test', 'b_hr', $this->bamenda);

        $this->as($clerk)->post(route('body.manage.users.store'), [
            'name' => 'Accomplice',
            'email' => 'accomplice@bamenda.test',
            'body_id' => $this->bamenda->id,
            'role_ids' => [Role::where('name', 'body_admin')->first()->id],
        ])->assertSessionHasErrors('role_ids');

        $this->assertDatabaseMissing('users', ['email' => 'accomplice@bamenda.test']);

        // Nor change the account of somebody above them.
        $this->put(route('body.manage.users.update', $this->admin), ['role_ids' => [$limited->id]])->assertForbidden();
    }

    public function test_nobody_can_change_their_own_roles(): void
    {
        $this->as($this->admin)->put(route('body.manage.users.update', $this->admin), [
            'role_ids' => [Role::where('name', 'auditor')->first()->id],
        ])->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->can('institution.create'));
    }

    public function test_a_role_from_another_diocese_cannot_be_given(): void
    {
        $theirs = Role::create(['body_id' => $this->buea->id, 'name' => 'b_buea', 'display_name' => 'Buea Clerk']);

        $this->as($this->admin)->post(route('body.manage.users.store'), [
            'name' => 'X',
            'email' => 'x@bamenda.test',
            'body_id' => $this->bamenda->id,
            'role_ids' => [$theirs->id],
        ])->assertSessionHasErrors('role_ids');
    }

    // --------------------------------------------------------------- roles

    public function test_a_body_can_create_a_role_and_it_takes_effect(): void
    {
        $this->as($this->admin)->post(route('body.manage.roles.store'), [
            'display_name' => 'Deputy Secretary',
            'permissions' => ['institution.view', 'finding.view'],
        ])->assertRedirect(route('body.manage.roles.index'))->assertSessionHasNoErrors();

        $role = Role::where('display_name', 'Deputy Secretary')->firstOrFail();

        $this->assertSame($this->bamenda->id, $role->body_id);
        $this->assertFalse($role->is_system);

        $deputy = $this->person('deputy@bamenda.test', $role->name, $this->bamenda);

        $this->assertTrue($deputy->can('finding.view'));
        $this->assertFalse($deputy->can('finding.resolve'));
    }

    public function test_a_role_cannot_grant_what_the_creator_lacks(): void
    {
        $limited = Role::create(['body_id' => $this->bamenda->id, 'name' => 'b_mgr', 'display_name' => 'Role Clerk']);
        $limited->permissions()->sync(Permission::whereIn('name', ['role.view', 'role.manage'])->pluck('id'));

        $this->as($this->person('rc@bamenda.test', 'b_mgr', $this->bamenda))->post(route('body.manage.roles.store'), [
            'display_name' => 'Back door',
            'permissions' => ['credential.issue'],
        ])->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['display_name' => 'Back door']);
    }

    public function test_platform_decisions_cannot_be_put_in_a_role(): void
    {
        $this->as($this->admin)->post(route('body.manage.roles.store'), [
            'display_name' => 'Too far',
            'permissions' => ['body.delete'],
        ])->assertSessionHasErrors('permissions');
    }

    public function test_platform_roles_and_other_dioceses_roles_cannot_be_edited(): void
    {
        $platform = Role::where('name', 'auditor')->first();
        $theirs = Role::create(['body_id' => $this->buea->id, 'name' => 'b_other', 'display_name' => 'Other']);

        $this->as($this->admin);

        $this->get(route('body.manage.roles.edit', $platform))->assertNotFound();
        $this->delete(route('body.manage.roles.destroy', $platform))->assertNotFound();
        $this->put(route('body.manage.roles.update', $theirs), ['display_name' => 'Mine'])->assertNotFound();
        $this->delete(route('body.manage.roles.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('roles', ['id' => $theirs->id, 'display_name' => 'Other']);
    }

    public function test_a_role_people_hold_cannot_be_deleted(): void
    {
        $role = Role::create(['body_id' => $this->bamenda->id, 'name' => 'b_used', 'display_name' => 'Used']);
        $this->person('holder@bamenda.test', 'b_used', $this->bamenda);

        $this->as($this->admin)->delete(route('body.manage.roles.destroy', $role))->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_a_deanery_admin_reaches_only_its_own_part_of_the_tree(): void
    {
        $parish = $this->institution($this->bamenda, 'Cathedral School');
        $own = $this->institution($this->deanery, 'Deanery School');

        $this->as($this->person('dean@bamenda.test', 'body_admin', $this->deanery));

        $this->get(route('body.manage.institutions.show', $own))->assertOk();
        $this->get(route('body.manage.institutions.show', $parish))->assertNotFound();

        $this->post(route('body.manage.institutions.store'), $this->school($this->bamenda))->assertSessionHasErrors('body_id');
    }

    // ----------------------------------------------------------- operator

    public function test_view_as_can_look_at_these_screens_but_change_nothing(): void
    {
        $operator = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'password', 'role' => 'super_admin']);

        $this->as($operator)->post(route('admin.bodies.impersonate', $this->bamenda));
        BodyContext::flush();

        $this->get(route('body.manage.users.index'))->assertOk();
        $this->post(route('body.manage.institutions.store'), $this->school($this->bamenda))->assertForbidden();

        $this->assertDatabaseMissing('institutions', ['name' => 'St Mary']);
    }

    public function test_the_operator_still_manages_from_their_own_side(): void
    {
        $operator = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'password', 'role' => 'super_admin']);

        $this->as($operator)->post(route('admin.institutions.store'), $this->school($this->buea))
            ->assertSessionHasNoErrors();

        $school = Institution::withoutBodyScope()->where('name', 'St Mary')->firstOrFail();

        $this->get(route('admin.institutions.show', $school))->assertOk();
    }

    // ------------------------------------------------------------- helpers

    private function as(User $user): self
    {
        $this->actingAs($user);
        BodyContext::flush();

        return $this;
    }

    private function makeBody(string $name, ?Body $parent = null): Body
    {
        return Body::create([
            'name' => $name,
            'parent_body_id' => $parent?->id,
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();
    }

    private function person(string $email, string $roleName, Body $body): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'role' => $roleName,
            'body_id' => $body->id,
            'invite_accepted_at' => now(),
        ]);

        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id);

        return $user;
    }

    private function institution(Body $body, string $name): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => 'school_secondary',
            'system' => 'edutrustshsm',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function school(Body $body): array
    {
        return [
            'body_id' => $body->id,
            'name' => 'St Mary',
            'type' => 'school_secondary',
            'system' => 'edutrustshsm',
            'connectivity_mode' => 'tunnel',
            'contract_version' => '1.0',
            'is_active' => 1,
        ];
    }
}
