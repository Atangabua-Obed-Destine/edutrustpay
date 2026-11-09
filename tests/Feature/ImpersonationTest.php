<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Body;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "View as this body" — the operator looking at what a body's own people see.
 *
 * Two things have to hold, and they pull in opposite directions. It must narrow
 * far enough to be honest (a view showing one institution more than the real
 * user gets is worse than no view at all), and it must not become a way to act
 * as somebody else. Both are tested through HTTP rather than against the
 * context class, because both are enforced by middleware and a unit test of
 * BodyContext would pass with that middleware unregistered.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Body $bamenda;

    private Body $deanery;

    private Body $buea;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->bamenda = $this->makeBody('Archdiocese of Bamenda');
        $this->deanery = $this->makeBody('Bamenda Central Deanery', $this->bamenda);
        $this->buea = $this->makeBody('Diocese of Buea');

        $this->makeInstitution($this->deanery, 'St Bede College Ashing');
        $this->makeInstitution($this->buea, 'Buea Diocesan Clinic');

        $this->operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'body_id' => null,
        ]);
    }

    public function test_the_operator_can_view_as_a_body_and_leave_again(): void
    {
        $this->actingAsOperator()
            ->post(route('admin.bodies.impersonate', $this->bamenda))
            ->assertRedirect(route('body.dashboard'));

        $this->assertSame($this->bamenda->id, session(BodyContext::IMPERSONATION_KEY));

        $this->post(route('impersonate.stop'))
            ->assertRedirect(route('admin.bodies.show', $this->bamenda));

        $this->assertNull(session(BodyContext::IMPERSONATION_KEY));
    }

    /**
     * The whole point. Impersonating a diocese must not leave the operator's
     * platform-wide reach switched on underneath.
     */
    public function test_impersonation_narrows_what_is_visible_to_that_subtree(): void
    {
        $this->actingAsOperator()->post(route('admin.bodies.impersonate', $this->bamenda));

        BodyContext::flush();

        $this->assertEqualsCanonicalizing(
            [$this->bamenda->id, $this->deanery->id],
            BodyContext::accessibleIds(),
            'A body nested inside the impersonated one belongs in the view; a sibling diocese does not.'
        );

        $this->get(route('body.institutions.index'))
            ->assertOk()
            ->assertSee('St Bede College Ashing')
            ->assertDontSee('Buea Diocesan Clinic');
    }

    public function test_another_bodys_institution_is_not_reachable_by_url_while_impersonating(): void
    {
        $clinic = Institution::withoutBodyScope()->where('name', 'Buea Diocesan Clinic')->firstOrFail();

        $this->actingAsOperator()->post(route('admin.bodies.impersonate', $this->bamenda));

        // 404 rather than 403, matching the rest of the console: a 403 confirms
        // the thing exists.
        $this->get(route('body.institutions.show', $clinic))->assertNotFound();
    }

    /**
     * The operator normally passes this route. While impersonating they must
     * not, or the record would carry a decision the body never made.
     */
    public function test_the_view_is_read_only(): void
    {
        $this->actingAsOperator()->post(route('admin.bodies.impersonate', $this->bamenda));

        $this->post(route('admin.bodies.store'), [
            'name' => 'Should Not Exist',
            'type' => 'diocese',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ]);

        $this->assertNull(Body::where('name', 'Should Not Exist')->first());
    }

    public function test_the_operator_console_redirects_rather_than_rendering_a_narrowed_page(): void
    {
        $this->actingAsOperator()->post(route('admin.bodies.impersonate', $this->bamenda));

        $this->get(route('admin.bodies.index'))->assertRedirect(route('body.dashboard'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('body.dashboard'));
    }

    public function test_signing_out_still_works_from_inside_the_view(): void
    {
        $this->actingAsOperator()->post(route('admin.bodies.impersonate', $this->bamenda));

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_a_body_user_cannot_impersonate(): void
    {
        $user = User::create([
            'name' => 'Bursary Head',
            'email' => 'bursar@example.test',
            'password' => 'password',
            'role' => 'body_user',
            'body_id' => $this->deanery->id,
        ]);

        if ($role = Role::where('name', 'bursary_head')->first()) {
            $user->roles()->attach($role->id);
        }

        $this->actingAs($user);
        BodyContext::flush();

        $this->post(route('admin.bodies.impersonate', $this->buea))->assertForbidden();
    }

    /**
     * A session key that outlived the operator's session must be inert rather
     * than a lever: nothing reachable through it that the account could not
     * already reach.
     */
    public function test_a_stale_impersonation_key_does_nothing_for_a_body_user(): void
    {
        $user = User::create([
            'name' => 'Bursary Head',
            'email' => 'bursar2@example.test',
            'password' => 'password',
            'role' => 'body_user',
            'body_id' => $this->deanery->id,
        ]);

        $this->actingAs($user);
        session([BodyContext::IMPERSONATION_KEY => $this->buea->id]);
        BodyContext::flush();

        $this->assertFalse(BodyContext::isImpersonating());
        $this->assertSame([$this->deanery->id], BodyContext::accessibleIds());
    }

    public function test_both_ends_of_the_view_are_recorded(): void
    {
        $this->actingAsOperator()->post(route('admin.bodies.impersonate', $this->bamenda));
        $this->post(route('impersonate.stop'));

        $started = AuditLog::withoutGlobalScopes()->where('action', 'body.impersonation_started')->first();
        $ended = AuditLog::withoutGlobalScopes()->where('action', 'body.impersonation_ended')->first();

        $this->assertNotNull($started, 'Impersonation is the one thing that makes one session look like another; it has to leave a record.');
        $this->assertNotNull($ended);
        $this->assertSame($this->operator->id, $started->user_id);
        $this->assertSame($this->bamenda->id, $started->body_id);
    }

    // ---------------------------------------------------------------

    private function actingAsOperator(): self
    {
        $this->actingAs($this->operator);
        BodyContext::flush();

        return $this;
    }

    private function makeBody(string $name, ?Body $parent = null): Body
    {
        return Body::create([
            'name' => $name,
            'type' => $parent ? 'deanery' : 'archdiocese',
            'parent_body_id' => $parent?->id,
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();
    }

    private function makeInstitution(Body $body, string $name): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => 'school_secondary',
            'system' => 'edutrustshsm',
        ]);
    }
}
