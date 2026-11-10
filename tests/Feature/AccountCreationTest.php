<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An administrator creates an account with a first password, and the person
 * can sign in at once; they choose their own password the first time, so the
 * administrator never knows the one the account is used with.
 */
class AccountCreationTest extends TestCase
{
    use RefreshDatabase;

    private Body $body;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);
        $this->admin = User::create(['body_id' => $this->body->id, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'admin-password-1', 'role' => 'body_admin', 'invite_accepted_at' => now()]);
        $this->admin->roles()->sync([Role::where('name', 'body_admin')->value('id')]);
    }

    private function asAdmin(): self
    {
        $this->actingAs($this->admin);
        BodyContext::flush();

        return $this;
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Secretary',
            'email' => 'ada@example.test',
            'body_id' => $this->body->id,
            'role_ids' => [Role::where('name', 'education_secretary')->value('id')],
            'how' => 'password',
            'password' => 'first-password-1',
            'password_confirmation' => 'first-password-1',
            'must_change' => '1',
        ], $overrides);
    }

    public function test_an_account_created_with_a_password_signs_in_at_once_and_chooses_its_own(): void
    {
        $this->asAdmin()->post(route('body.manage.users.store'), $this->details())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'can sign in now'));

        $ada = User::where('email', 'ada@example.test')->sole();
        $this->assertTrue($ada->must_change_password);
        $this->assertNotNull($ada->invite_accepted_at, 'Shown as active, not as a pending invitation.');
        $this->assertNull($ada->invite_token);

        auth()->logout();
        $this->post(route('login.store'), ['email' => 'ada@example.test', 'password' => 'first-password-1'])->assertRedirect();

        // Nothing else opens until they have chosen their own password.
        $this->get(route('body.dashboard'))->assertRedirect(route('account.password'));
        $this->get(route('account.password'))->assertOk()->assertSee('Choose your own password')->assertDontSee('Current password');

        $this->post(route('account.password.update'), ['password' => 'my-own-password-2', 'password_confirmation' => 'my-own-password-2'])->assertRedirect();

        $ada->refresh();
        $this->assertFalse($ada->must_change_password);
        $this->assertTrue(Hash::check('my-own-password-2', $ada->password));
        $this->get(route('body.dashboard'))->assertOk();
    }

    public function test_the_first_password_can_be_kept_when_the_administrator_says_so(): void
    {
        $this->asAdmin()->post(route('body.manage.users.store'), $this->details(['must_change' => '0']));

        $this->assertFalse(User::where('email', 'ada@example.test')->sole()->must_change_password);
    }

    public function test_a_password_too_short_or_mistyped_creates_nothing(): void
    {
        $this->asAdmin()->post(route('body.manage.users.store'), $this->details(['password' => 'short', 'password_confirmation' => 'other']))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::where('email', 'ada@example.test')->count());
    }

    public function test_a_link_can_still_be_sent_instead(): void
    {
        $this->asAdmin()->post(route('body.manage.users.store'), $this->details(['how' => 'link', 'password' => '', 'password_confirmation' => '']))
            ->assertSessionHas('invite');

        $this->assertNull(User::where('email', 'ada@example.test')->sole()->invite_accepted_at);
    }

    public function test_an_administrator_sets_a_new_password_for_someone_who_forgot_theirs(): void
    {
        $this->asAdmin()->post(route('body.manage.users.store'), $this->details(['must_change' => '0']));
        $ada = User::where('email', 'ada@example.test')->sole();

        $this->get(route('body.manage.users.index'))->assertSee('Set a new password');

        $this->post(route('body.manage.users.password.update', $ada), ['password' => 'reset-password-3', 'password_confirmation' => 'reset-password-3', 'must_change' => '1'])
            ->assertRedirect();

        $ada->refresh();
        $this->assertTrue(Hash::check('reset-password-3', $ada->password));
        $this->assertTrue($ada->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_set', 'model_id' => $ada->id]);
    }

    public function test_changing_your_own_password_needs_the_current_one(): void
    {
        $this->asAdmin();

        $this->post(route('account.password.update'), ['current_password' => 'wrong', 'password' => 'new-password-9', 'password_confirmation' => 'new-password-9'])
            ->assertSessionHasErrors('current_password');

        $this->post(route('account.password.update'), ['current_password' => 'admin-password-1', 'password' => 'new-password-9', 'password_confirmation' => 'new-password-9'])
            ->assertRedirect();
        $this->assertTrue(Hash::check('new-password-9', $this->admin->fresh()->password));
    }

    public function test_someone_without_the_permission_cannot_set_another_persons_password(): void
    {
        $this->asAdmin()->post(route('body.manage.users.store'), $this->details(['must_change' => '0']));
        $ada = User::where('email', 'ada@example.test')->sole();

        $this->actingAs($ada);
        BodyContext::flush();

        $this->post(route('body.manage.users.password.update', $this->admin), ['password' => 'taken-over-1', 'password_confirmation' => 'taken-over-1'])
            ->assertForbidden();
        $this->assertTrue(Hash::check('admin-password-1', $this->admin->fresh()->password));
    }
}
