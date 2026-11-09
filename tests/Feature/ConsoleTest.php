<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\InstitutionCapability;
use App\Models\InstitutionCredential;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Simulator\PushClient;
use App\Simulator\ScenarioGenerator;
use App\Support\BodyContext;
use EdutrustPay\Contract\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The body console.
 *
 * These test what the console SAYS, not merely that it responds. A page that
 * returns 200 while quietly rendering a missing figure as 0 would pass a smoke
 * test and fail at the only thing this platform is for.
 */
class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Body $bamenda;

    private Body $buea;

    private Institution $reporting;

    private Institution $silent;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->bamenda = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);
        $this->buea = Body::create(['name' => 'Diocese of Buea', 'type' => 'diocese']);

        // Reports a ledger, and nothing else. Its receivables must render as
        // "not yet reporting" — the central assertion of this file.
        $this->reporting = $this->institution($this->bamenda, 'St Bede College', [Capability::LEDGER]);

        // Enrolled but has never pushed: a failed onboarding, not a lapse.
        $this->silent = $this->institution($this->bamenda, 'St Augustine College', [Capability::LEDGER]);
        $this->silent->forceFill(['created_at' => now()->subMonths(6)])->saveQuietly();

        $this->institution($this->buea, 'Buea Diocesan Clinic', [Capability::LEDGER]);

        $this->pushMonths($this->reporting, 3);
    }

    // ---------------------------------------------------------------
    // What the console says
    // ---------------------------------------------------------------

    public function test_the_overview_does_not_pass_off_a_partial_total_as_the_whole(): void
    {
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard', ['period' => now()->subMonth()->format('Y-m')]))
            ->assertOk()
            // Never a bare number: brief §7.4 requires coverage to be explicit.
            ->assertSee('only 1 of 2 sent');
    }

    public function test_the_overview_totals_a_figure_once_every_school_has_sent_it(): void
    {
        $this->pushMonths($this->silent, 3);

        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard', ['period' => now()->subMonth()->format('Y-m')]))
            ->assertOk()
            ->assertDontSee('only 1 of 2 sent');
    }

    public function test_the_overview_says_in_words_who_reported_and_who_did_not(): void
    {
        $month = now()->subMonth();

        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard', ['period' => $month->format('Y-m')]))
            ->assertOk()
            ->assertSee('1 of 2')
            ->assertSee('Still missing: St Augustine College')
            ->assertSee($month->format('F Y'))
            ->assertDontSee('Buea Diocesan Clinic');
    }

    public function test_a_month_still_running_is_not_reported_as_missing(): void
    {
        $this->pushMonths($this->silent, 3);

        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard', ['period' => now()->addMonth()->format('Y-m')]))
            ->assertOk()
            ->assertSee('has not finished, so no report is due yet')
            ->assertDontSee('Still missing');
    }

    public function test_the_overview_says_so_when_no_rule_has_been_set(): void
    {
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard'))
            ->assertOk()
            ->assertSee('You have not set any rules yet');
    }

    public function test_a_capability_that_was_never_declared_renders_as_words_not_zero(): void
    {
        $period = now()->subMonth()->format('Y-m');

        $response = $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.institutions.show', ['institution' => $this->reporting, 'period' => $period]))
            ->assertOk();

        // The institution reports a ledger but not receivables. The console must
        // say so in words.
        $response->assertSee("doesn't send this");
    }

    public function test_a_silent_institution_is_named_on_the_landing_page(): void
    {
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard'))
            ->assertOk()
            ->assertSee('St Augustine College')
            // Never reported at all reads differently from having stopped.
            ->assertSee('never reported');
    }

    public function test_the_capability_matrix_separates_a_closing_gap_from_a_permanent_one(): void
    {
        $pharmacy = $this->institution($this->bamenda, 'Shisong Pharmacy', [Capability::LEDGER]);
        $pharmacy->forceFill(['type' => 'business'])->saveQuietly();

        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.capabilities'))
            ->assertOk()
            ->assertSee('not yet')   // will close as systems gain modules
            ->assertSee('n/a')       // enrolment at a pharmacy never will
            ->assertSee('Sent separately');  // the daybook and budget sheet are their own documents
    }

    public function test_the_consolidated_statement_exports_as_a_pdf(): void
    {
        $response = $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.consolidation.export', ['period' => now()->subMonth()->format('Y-m')]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_export_is_recorded_in_the_audit_trail(): void
    {
        // Who exported the group's financial position, and when, is part of the
        // evidence in a system whose purpose is oversight.
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.consolidation.export', ['period' => now()->subMonth()->format('Y-m')]));

        $this->assertDatabaseHas('audit_logs', ['action' => 'consolidation.exported']);
    }

    // ---------------------------------------------------------------
    // Tenancy, through HTTP
    // ---------------------------------------------------------------

    public function test_a_body_user_cannot_see_another_bodys_institutions_in_the_console(): void
    {
        $this->actingAsBodyUser('bursary_head', $this->buea)
            ->get(route('body.institutions.index'))
            ->assertOk()
            ->assertSee('Buea Diocesan Clinic')
            ->assertDontSee('St Bede College');
    }

    public function test_a_body_user_gets_a_404_for_another_bodys_institution_by_url(): void
    {
        // The global scope means the record is simply not there for this user,
        // so route-model binding produces a 404 rather than a 403 — which also
        // avoids confirming that the institution exists at all.
        $this->actingAsBodyUser('bursary_head', $this->buea)
            ->get(route('body.institutions.show', $this->reporting))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Findings and permissions
    // ---------------------------------------------------------------

    public function test_an_auditor_can_see_a_finding_but_not_answer_it(): void
    {
        $finding = $this->finding();

        // An auditor who can close the findings they are auditing is not an
        // auditor. They see everything and resolve nothing.
        $auditor = $this->actingAsBodyUser('auditor', $this->bamenda);

        $auditor->get(route('body.findings.show', $finding))->assertOk();

        $auditor->post(route('body.findings.resolve', $finding), [
            'status' => Finding::ACCEPTED,
            'note' => 'Spoke to the bursar, this is fine.',
        ])->assertForbidden();

        $this->assertSame(Finding::OPEN, $finding->fresh()->status);
    }

    public function test_answering_a_finding_requires_a_written_reason(): void
    {
        $finding = $this->finding();

        // An "accepted" with nothing written against it is indistinguishable
        // from someone clearing their inbox.
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->post(route('body.findings.resolve', $finding), ['status' => Finding::ACCEPTED, 'note' => ''])
            ->assertSessionHasErrors('note');

        $this->assertSame(Finding::OPEN, $finding->fresh()->status);
    }

    public function test_answering_a_finding_records_who_decided_and_why(): void
    {
        $finding = $this->finding();
        $user = $this->bodyUser('bursary_head', $this->bamenda);

        $this->actingAs($user);
        BodyContext::flush();

        $this->post(route('body.findings.resolve', $finding), [
            'status' => Finding::EXPLAINED,
            'note' => 'The deanery confirmed the reversal was a duplicate receipt.',
        ])->assertRedirect(route('body.findings.index'));

        $finding->refresh();

        $this->assertSame(Finding::EXPLAINED, $finding->status);
        $this->assertSame($user->id, $finding->resolved_by);
        $this->assertNotNull($finding->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finding.explained']);
    }

    // ---------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------

    public function test_the_console_requires_authentication(): void
    {
        $this->get(route('body.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_body_user_cannot_reach_the_operator_area(): void
    {
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_the_operator_sees_every_body(): void
    {
        $operator = User::create([
            'name' => 'Operator', 'email' => 'op@example.test',
            'password' => 'secret', 'role' => 'super_admin',
        ]);

        $this->actingAs($operator);
        BodyContext::flush();

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Archdiocese of Bamenda')
            ->assertSee('Diocese of Buea');
    }

    public function test_an_inactive_account_cannot_sign_in(): void
    {
        $user = $this->bodyUser('bursary_head', $this->bamenda);
        $user->forceFill(['is_active' => false])->saveQuietly();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function institution(Body $body, string $name, array $capabilities): Institution
    {
        $institution = Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
        ]);

        foreach ($capabilities as $capability) {
            InstitutionCapability::create([
                'institution_id' => $institution->id,
                'capability' => $capability,
                'source' => 'declared',
                'declared_at' => now(),
            ]);
        }

        return $institution;
    }

    private function pushMonths(Institution $institution, int $months): void
    {
        $issued = InstitutionCredential::issue($institution, 'Test');
        $client = new PushClient($issued['credential'], $issued['secret']);
        $generator = new ScenarioGenerator(1);

        $cursor = now()->startOfMonth()->subMonths($months);

        for ($i = 0; $i < $months; $i++) {
            $period = $cursor->addMonth()->format('Y-m');
            $payload = $generator->build($institution, $period, $i, $months, 'clean', [Capability::LEDGER]);
            $client->pushReport($payload);
        }
    }

    private function finding(): Finding
    {
        return Finding::withoutBodyScope()->create([
            'body_id' => $this->bamenda->id,
            'institution_id' => $this->silent->id,
            'rule' => 'not_reporting',
            'severity' => 'high',
            'title' => 'Not reporting',
            'explanation' => 'This institution has never sent a report.',
            'first_detected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function bodyUser(string $roleName, Body $body): User
    {
        $user = User::create([
            'body_id' => $body->id,
            'name' => ucfirst($roleName).' of '.$body->name,
            'email' => $roleName.$body->id.'@example.test',
            'password' => 'password',
            'role' => $roleName,
        ]);

        $user->roles()->sync([Role::where('name', $roleName)->value('id')]);

        return $user;
    }

    public function test_the_overview_welcomes_the_person_and_leads_with_the_key_figures(): void
    {
        $this->actingAsBodyUser('bursary_head', $this->bamenda)
            ->get(route('body.dashboard'))
            ->assertOk()
            ->assertSee('Welcome,')
            ->assertSee('How this console works')
            ->assertSeeInOrder(['Reports for', 'To look into', 'Fees collected so far', 'Fees owed', 'Income less spending', 'Start here'])
            // A total that leaves a school out says so beside the figure.
            ->assertSee('schools only.');
    }

    public function test_the_start_here_guide_stays_until_the_person_hides_it_and_can_be_brought_back(): void
    {
        $user = $this->bodyUser('bursary_head', $this->bamenda);
        $this->actingAs($user);
        BodyContext::flush();

        $this->get(route('body.dashboard'))->assertSee('Start here');

        $this->post(route('body.guide.hide'))->assertRedirect(route('body.dashboard'));
        $this->get(route('body.dashboard'))->assertDontSee('Start here')->assertSee('How this console works');
        $this->assertNotNull($user->fresh()->guide_hidden_at, 'Hidden for the person, not the browser.');

        $this->get(route('body.guide'))->assertSee('Show it again');
        $this->post(route('body.guide.restore'))->assertRedirect(route('body.dashboard'));
        $this->get(route('body.dashboard'))->assertSee('Start here');
    }

    public function test_the_guide_describes_only_the_pages_the_person_can_open(): void
    {
        $this->actingAsBodyUser('auditor', $this->bamenda)
            ->get(route('body.guide'))
            ->assertOk()
            ->assertSee(route('body.compliance.index'))
            ->assertSee(route('body.consolidation'));

        // A liaison sees one school's reports and findings, and nothing added up,
        // so the guide never sends them to a page they would be refused.
        $this->actingAsBodyUser('institution_liaison', $this->bamenda)
            ->get(route('body.guide'))
            ->assertOk()
            ->assertSee('What each page is for')
            ->assertSee(route('body.findings.index'))
            ->assertDontSee(route('body.compliance.index'))
            ->assertDontSee(route('body.consolidation'))
            ->assertDontSee(route('body.daybook.compare'));
    }

    private function actingAsBodyUser(string $roleName, Body $body): self
    {
        $this->actingAs($this->bodyUser($roleName, $body));
        BodyContext::flush();

        return $this;
    }
}
