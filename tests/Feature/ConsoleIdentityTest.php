<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whose identity a page wears.
 *
 * "Which body am I looking at" and "whose crest and colour do I wear" look like
 * the same question and are not. The operator belongs to no body, so
 * `current()` falls through to the first accessible one — which meant creating
 * a body and giving it a colour silently restyled the operator's own console.
 *
 * The distinction only shows up once a body sets an accent, so it is exactly
 * the kind of thing that comes back unless something holds it down.
 */
class ConsoleIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Body $body;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->body = Body::create([
            'name' => 'Diocese of Bamenda',
            'type' => 'Diocese',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
            'accent_colour' => '#60521f',
        ])->fresh();

        $this->operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'body_id' => null,
        ]);
    }

    public function test_the_operator_wears_the_platform_identity_not_the_first_bodys(): void
    {
        $this->actingAs($this->operator);
        BodyContext::flush();

        $this->assertNull(BodyContext::chromeBody(), 'The operator is outside every body.');
        $this->assertSame(config('platform.accent'), BodyContext::accent());

        $this->get(route('admin.bodies.index'))
            ->assertOk()
            ->assertSee('--body-accent: '.config('platform.accent'), false)
            ->assertDontSee('#60521f');
    }

    /**
     * Impersonation is the one case where the operator SHOULD wear a body's
     * colours — seeing what that body's people see is the entire point.
     */
    public function test_impersonation_puts_the_operator_in_the_bodys_colours(): void
    {
        $this->actingAs($this->operator);
        BodyContext::flush();

        $this->post(route('admin.bodies.impersonate', $this->body));
        BodyContext::flush();

        $this->assertSame($this->body->id, BodyContext::chromeBody()?->id);
        $this->assertSame('#60521f', BodyContext::accent());

        $this->get(route('body.dashboard'))->assertOk()->assertSee('#60521f', false);
    }

    public function test_a_body_user_wears_their_own_bodys_colours(): void
    {
        $user = User::create([
            'name' => 'Bursary Head',
            'email' => 'bursar@example.test',
            'password' => 'password',
            'role' => 'body_user',
            'body_id' => $this->body->id,
        ]);

        if ($role = Role::where('name', 'bursary_head')->first()) {
            $user->roles()->attach($role->id);
        }

        $this->actingAs($user);
        BodyContext::flush();

        $this->assertSame($this->body->id, BodyContext::chromeBody()?->id);
        $this->assertSame('#60521f', BodyContext::accent());
    }

    /**
     * The same root cause, somewhere it matters more than colour.
     *
     * body_id is what scopeVisible() uses to decide which trail entries a
     * body's people may read. Guessing one for the operator both misattributes
     * the action and shows a body's auditor an entry that was never theirs.
     */
    public function test_an_operators_login_is_not_attributed_to_a_body(): void
    {
        $this->actingAs($this->operator);
        BodyContext::flush();

        \App\Models\AuditLog::log('auth.login', User::class, $this->operator->id);

        $entry = \App\Models\AuditLog::withoutGlobalScopes()->where('action', 'auth.login')->latest('id')->first();

        $this->assertNull($entry->body_id, 'A platform-level event belongs to no body.');
    }

    /**
     * And an action that IS about a body carries that body — derived from the
     * subject, not from whoever happens to be looking.
     */
    public function test_creating_a_body_is_attributed_to_that_body(): void
    {
        $this->actingAs($this->operator);
        BodyContext::flush();

        $created = Body::create([
            'name' => 'Diocese of Buea',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();

        $entry = \App\Models\AuditLog::withoutGlobalScopes()
            ->where('model_type', Body::class)->where('model_id', $created->id)
            ->where('action', 'created')->firstOrFail();

        $this->assertSame($created->id, $entry->body_id, 'Not the first body that happened to sort first.');
    }

    /**
     * A body that has set no colour inherits, rather than falling back to a
     * bare default that would make it look unconfigured.
     */
    public function test_a_nested_body_inherits_its_parents_colour(): void
    {
        $deanery = Body::create([
            'name' => 'Bamenda Central Deanery',
            'type' => 'Deanery',
            'parent_body_id' => $this->body->id,
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();

        $this->assertSame('#60521f', $deanery->accent());
    }
}
