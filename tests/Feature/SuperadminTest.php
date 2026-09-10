<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCapability;
use App\Models\InstitutionCredential;
use App\Models\OnboardingStep;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use EdutrustPay\Contract\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator area.
 *
 * This is where credentials are issued and people are given access, so the tests
 * lean on the things that would be expensive to get wrong: a secret shown twice,
 * a liaison who can see the whole body, an invitation link that keeps working.
 */
class SuperadminTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            // The operator belongs to no body — they are outside every one.
            'body_id' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Bodies
    // ---------------------------------------------------------------

    public function test_the_operator_can_create_a_body(): void
    {
        $this->actingAsOperator()
            ->post(route('admin.bodies.store'), [
                'name' => 'Archdiocese of Bamenda',
                'type' => 'archdiocese',
                'country' => 'Cameroon',
                'fiscal_year_start_month' => 1,
                'currency' => 'XAF',
                'is_active' => 1,
            ])
            ->assertRedirect();

        $body = Body::where('name', 'Archdiocese of Bamenda')->first();

        $this->assertNotNull($body);
        $this->assertSame('archdiocese-of-bamenda', $body->slug);
        $this->assertSame('/'.$body->id.'/', $body->path);
        $this->assertSame(0, $body->depth);
    }

    /**
     * A body's type is a label, not a classification.
     *
     * It used to be a required choice from seven ecclesial terms, which could
     * not describe half the organisations this platform will hold — a Regional
     * Delegation, a congregation, a trust. Each would have been filed as
     * "other", which the sidebar then renders as the literal word "other".
     */
    public function test_a_body_type_can_be_anything_the_organisation_calls_itself(): void
    {
        $this->actingAsOperator()
            ->post(route('admin.bodies.store'), [
                'name' => 'Regional Delegation for the Northwest',
                'type' => 'Regional Delegation',
                'country' => 'Cameroon',
                'fiscal_year_start_month' => 1,
                'currency' => 'XAF',
                'is_active' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(
            'Regional Delegation',
            Body::where('name', 'Regional Delegation for the Northwest')->value('type')
        );
    }

    /**
     * And it is optional: nothing branches on it, so an unknown type is better
     * recorded as nothing than guessed at.
     */
    public function test_a_body_needs_no_type_at_all(): void
    {
        $this->actingAsOperator()
            ->post(route('admin.bodies.store'), [
                'name' => 'Bamenda Health Board',
                'country' => 'Cameroon',
                'fiscal_year_start_month' => 1,
                'currency' => 'XAF',
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(Body::where('name', 'Bamenda Health Board')->value('type'));
    }

    public function test_a_nested_body_gets_the_right_path_and_depth(): void
    {
        $parent = $this->makeBody('Archdiocese of Bamenda');

        $this->actingAsOperator()->post(route('admin.bodies.store'), [
            'name' => 'Bamenda Central Deanery',
            'type' => 'deanery',
            'parent_body_id' => $parent->id,
            'country' => 'Cameroon',
            'fiscal_year_start_month' => 1,
            'currency' => 'XAF',
        ])->assertRedirect();

        $child = Body::where('name', 'Bamenda Central Deanery')->first();

        $this->assertSame('/'.$parent->id.'/'.$child->id.'/', $child->path);
        $this->assertSame(1, $child->depth);
    }

    public function test_a_body_cannot_be_moved_inside_itself(): void
    {
        $parent = $this->makeBody('Archdiocese');
        $child = $this->makeBody('Deanery', $parent);

        // Without this guard the path rebuild recurses until memory runs out
        // and leaves the tree half-written.
        $this->actingAsOperator()
            ->put(route('admin.bodies.update', $parent), $this->bodyPayload($parent, ['parent_body_id' => $child->id]))
            ->assertSessionHasErrors('parent_body_id');

        $this->assertNull($parent->fresh()->parent_body_id);
    }

    public function test_a_body_holding_institutions_cannot_be_deleted(): void
    {
        $body = $this->makeBody('Archdiocese');
        $this->makeInstitution($body, 'St Bede College');

        // Nothing is deleted where money is concerned: this body has reports,
        // findings and an audit trail hanging off it.
        $this->actingAsOperator()
            ->delete(route('admin.bodies.destroy', $body))
            ->assertSessionHas('error');

        $this->assertNotNull($body->fresh());
    }

    public function test_an_empty_body_can_be_deleted(): void
    {
        $body = $this->makeBody('Mistake');

        $this->actingAsOperator()->delete(route('admin.bodies.destroy', $body))->assertRedirect();

        $this->assertNull(Body::find($body->id));
    }

    // ---------------------------------------------------------------
    // Institutions and capabilities
    // ---------------------------------------------------------------

    public function test_enrolling_an_institution_generates_a_uuid(): void
    {
        $body = $this->makeBody('Archdiocese');

        $this->actingAsOperator()->post(route('admin.institutions.store'), [
            'body_id' => $body->id,
            'name' => 'St Bede College',
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
            'connectivity_mode' => 'push',
            'contract_version' => '1.0',
        ])->assertRedirect();

        $institution = Institution::withoutBodyScope()->where('name', 'St Bede College')->first();

        $this->assertNotNull($institution);
        // A UUID rather than the numeric id: it is quoted back over a public
        // endpoint, and sequential ids invite enumeration.
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $institution->uuid);
    }

    public function test_a_capability_that_cannot_apply_is_refused(): void
    {
        $body = $this->makeBody('Archdiocese');
        $pharmacy = $this->makeInstitution($body, 'Shisong Pharmacy', 'business');

        $this->actingAsOperator()->put(route('admin.institutions.capabilities', $pharmacy), [
            'capabilities' => [Capability::LEDGER, Capability::ENROLMENT],
        ])->assertRedirect();

        // A pharmacy has no enrolment, ever. Accepting it would put a
        // permanently unfillable gap on the capability matrix.
        $this->assertEqualsCanonicalizing(
            [Capability::LEDGER],
            $pharmacy->fresh()->declaredCapabilities()
        );
    }

    public function test_an_observed_capability_cannot_be_removed_by_hand(): void
    {
        $body = $this->makeBody('Archdiocese');
        $institution = $this->makeInstitution($body, 'St Bede College');

        InstitutionCapability::create([
            'institution_id' => $institution->id,
            'capability' => Capability::LEDGER,
            'source' => 'observed',
            'first_seen_at' => now(),
        ]);

        // Reports carrying it have already arrived. Removing it here would make
        // the capability matrix disagree with the figures underneath it.
        $this->actingAsOperator()
            ->put(route('admin.institutions.capabilities', $institution), ['capabilities' => []])
            ->assertRedirect();

        $this->assertContains(Capability::LEDGER, $institution->fresh()->declaredCapabilities());
    }

    // ---------------------------------------------------------------
    // Credentials
    // ---------------------------------------------------------------

    public function test_issuing_a_credential_shows_the_secret_exactly_once(): void
    {
        $body = $this->makeBody('Archdiocese');
        $institution = $this->makeInstitution($body, 'St Bede College');

        $response = $this->actingAsOperator()
            ->post(route('admin.credentials.store', $institution), ['label' => 'bursary server']);

        $secret = session('secret');

        $this->assertNotNull($secret);
        $this->assertNotEmpty($secret['secret']);

        // Flashed, never stored readable. There is no "show secret" anywhere.
        $response->assertRedirect(route('admin.institutions.show', $institution));
        $this->assertSame(1, $institution->credentials()->count());
    }

    public function test_rotation_issues_a_new_key_and_leaves_the_old_one_alive(): void
    {
        $body = $this->makeBody('Archdiocese');
        $institution = $this->makeInstitution($body, 'St Bede College');
        $original = InstitutionCredential::issue($institution, 'first')['credential'];

        $this->actingAsOperator()
            ->post(route('admin.credentials.rotate', [$institution, $original]))
            ->assertRedirect();

        $this->assertSame(2, $institution->credentials()->count());

        // The old key keeps working until the institution confirms the new one:
        // a client that crashed before writing the secret must not be locked out.
        $this->assertNotNull($original->fresh()->rotated_at);
    }

    public function test_revoking_requires_a_reason(): void
    {
        $body = $this->makeBody('Archdiocese');
        $institution = $this->makeInstitution($body, 'St Bede College');
        $credential = InstitutionCredential::issue($institution, 'first')['credential'];

        $this->actingAsOperator()
            ->post(route('admin.credentials.revoke', [$institution, $credential]), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($credential->fresh()->isActive());

        $this->actingAsOperator()
            ->post(route('admin.credentials.revoke', [$institution, $credential]), ['reason' => 'Bursar left on 30 August'])
            ->assertRedirect();

        $credential->refresh();

        $this->assertFalse($credential->isActive());
        // Retained, never deleted, so a report signed with it can still be
        // explained later.
        $this->assertSame('Bursar left on 30 August', $credential->revoked_reason);
    }

    public function test_a_credential_belonging_to_another_institution_is_not_reachable(): void
    {
        $body = $this->makeBody('Archdiocese');
        $ours = $this->makeInstitution($body, 'St Bede College');
        $theirs = $this->makeInstitution($body, 'St Augustine College');

        $credential = InstitutionCredential::issue($theirs, 'theirs')['credential'];

        $this->actingAsOperator()
            ->post(route('admin.credentials.revoke', [$ours, $credential]), ['reason' => 'wrong institution'])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Onboarding
    // ---------------------------------------------------------------

    public function test_a_manual_step_can_be_ticked(): void
    {
        $body = $this->makeBody('Archdiocese');
        $institution = $this->makeInstitution($body, 'St Bede College');

        $this->actingAsOperator()->post(route('admin.institutions.onboarding', $institution), [
            'step' => 'ohada_mapping',
            'done' => 1,
        ])->assertRedirect();

        $step = OnboardingStep::where('institution_id', $institution->id)->where('step', 'ohada_mapping')->first();

        $this->assertNotNull($step->completed_at);
        $this->assertSame($this->operator->id, $step->completed_by);
    }

    public function test_an_observed_step_cannot_be_ticked_by_hand(): void
    {
        $body = $this->makeBody('Archdiocese');
        $institution = $this->makeInstitution($body, 'St Bede College');

        // Asking somebody to tick a box the database can answer is how
        // checklists start lying.
        $this->actingAsOperator()->post(route('admin.institutions.onboarding', $institution), [
            'step' => 'first_push',
            'done' => 1,
        ])->assertRedirect();

        $this->assertSame(0, OnboardingStep::where('institution_id', $institution->id)->count());
    }

    // ---------------------------------------------------------------
    // Invitations
    // ---------------------------------------------------------------

    public function test_inviting_somebody_never_generates_a_password(): void
    {
        $body = $this->makeBody('Archdiocese');

        $this->actingAsOperator()->post(route('admin.users.store'), [
            'name' => 'Bursary Head',
            'email' => 'bursar@example.test',
            'body_id' => $body->id,
            'role_id' => Role::where('name', 'bursary_head')->value('id'),
        ])->assertRedirect();

        $user = User::where('email', 'bursar@example.test')->first();

        $this->assertNotNull($user->invite_token);
        $this->assertNull($user->invite_accepted_at);

        // The link is flashed once; nothing resembling a password is ever
        // shown, stored in the clear, or emailed.
        $invite = session('invite');
        $this->assertStringContainsString($user->invite_token, $invite['url']);
    }

    public function test_a_liaison_without_an_institution_is_refused(): void
    {
        $body = $this->makeBody('Archdiocese');

        // With no institution set, BodyScope's liaison narrowing does not apply
        // and they would quietly see the whole body.
        $this->actingAsOperator()->post(route('admin.users.store'), [
            'name' => 'Liaison',
            'email' => 'liaison@example.test',
            'body_id' => $body->id,
            'role_id' => Role::where('name', 'institution_liaison')->value('id'),
        ])->assertSessionHasErrors('institution_id');

        $this->assertNull(User::where('email', 'liaison@example.test')->first());
    }

    public function test_a_liaison_cannot_be_tied_to_another_bodys_institution(): void
    {
        $ours = $this->makeBody('Archdiocese');
        $theirs = $this->makeBody('Diocese of Buea');
        $institution = $this->makeInstitution($theirs, 'Buea Clinic');

        $this->actingAsOperator()->post(route('admin.users.store'), [
            'name' => 'Liaison',
            'email' => 'liaison@example.test',
            'body_id' => $ours->id,
            'role_id' => Role::where('name', 'institution_liaison')->value('id'),
            'institution_id' => $institution->id,
        ])->assertSessionHasErrors('institution_id');
    }

    public function test_an_invitation_is_single_use(): void
    {
        $body = $this->makeBody('Archdiocese');
        $user = $this->invite($body);
        $token = $user->invite_token;

        $this->post(route('invite.store', $token), [
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
        ])->assertRedirect(route('body.dashboard'));

        $user->refresh();
        $this->assertNull($user->invite_token);
        $this->assertNotNull($user->invite_accepted_at);

        // The same link, forwarded or left in an inbox, is now dead.
        $this->get(route('invite.show', $token))->assertNotFound();
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        $body = $this->makeBody('Archdiocese');
        $user = $this->invite($body);

        $user->forceFill(['invite_sent_at' => now()->subDays(30)])->save();

        $this->get(route('invite.show', $user->invite_token))->assertNotFound();
    }

    public function test_the_accepted_user_lands_scoped_to_their_own_body(): void
    {
        $ours = $this->makeBody('Archdiocese');
        $theirs = $this->makeBody('Diocese of Buea');
        $this->makeInstitution($ours, 'St Bede College');
        $this->makeInstitution($theirs, 'Buea Clinic');

        $user = $this->invite($ours);

        $this->post(route('invite.store', $user->invite_token), [
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
        ]);

        BodyContext::flush();

        $this->get(route('body.institutions.index'))
            ->assertOk()
            ->assertSee('St Bede College')
            ->assertDontSee('Buea Clinic');
    }

    // ---------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------

    public function test_a_body_user_cannot_reach_the_operator_area(): void
    {
        $body = $this->makeBody('Archdiocese');

        $user = User::create([
            'name' => 'Bursary Head', 'email' => 'bh@example.test',
            'password' => 'password', 'role' => 'bursary_head', 'body_id' => $body->id,
        ]);

        $this->actingAs($user);
        BodyContext::flush();

        foreach ([
            route('admin.bodies.index'),
            route('admin.institutions.index'),
            route('admin.users.index'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    // ---------------------------------------------------------------
    // Helpers
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

    private function makeInstitution(Body $body, string $name, string $type = 'school_basic'): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => $type,
            'system' => $type === 'business' ? 'posinnova' : 'edutrustshsm',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bodyPayload(Body $body, array $overrides = []): array
    {
        return array_merge([
            'name' => $body->name,
            'type' => $body->type,
            'country' => $body->country,
            'currency' => $body->currency,
            'fiscal_year_start_month' => $body->fiscal_year_start_month,
        ], $overrides);
    }

    private function invite(Body $body): User
    {
        $this->actingAsOperator()->post(route('admin.users.store'), [
            'name' => 'Bursary Head',
            'email' => 'bursar@example.test',
            'body_id' => $body->id,
            'role_id' => Role::where('name', 'bursary_head')->value('id'),
        ]);

        auth()->logout();
        BodyContext::flush();

        return User::where('email', 'bursar@example.test')->first();
    }
}
