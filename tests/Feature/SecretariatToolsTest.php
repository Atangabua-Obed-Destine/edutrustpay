<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Body;
use App\Models\BudgetSheet;
use App\Models\DaybookFiling;
use App\Models\DaybookLine;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\InstitutionNote;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the Education Secretariat and the finance office use around the
 * figures: who to call, notes on a school, downloads for Excel, the record of
 * who did what, To look into narrowed to one school, and plain error pages.
 */
class SecretariatToolsTest extends TestCase
{
    use RefreshDatabase;

    private Body $body;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);
        $this->school = Institution::withoutBodyScope()->create([
            'body_id' => $this->body->id, 'name' => 'St Jerome', 'type' => 'school_secondary', 'system' => 'edutrustshsm',
            'contact_name' => 'Mr Example Bursar', 'contact_phone' => '+237 670 00 00 00', 'contact_email' => 'bursar@example.test',
        ]);
    }

    private function as(string $role): User
    {
        $user = User::create(['body_id' => $this->body->id, 'name' => ucfirst(str_replace('_', ' ', $role)), 'email' => $role.'@example.test', 'password' => 'password', 'role' => $role,
            'institution_id' => $role === 'institution_liaison' ? $this->school->id : null]);
        $user->roles()->sync([Role::where('name', $role)->value('id')]);
        $this->actingAs($user);
        BodyContext::flush();

        return $user;
    }

    public function test_the_school_page_says_who_to_call(): void
    {
        $this->as('education_secretary');

        $this->get(route('body.institutions.show', $this->school))
            ->assertOk()
            ->assertSeeInOrder(['Who to call', 'Mr Example Bursar', '+237 670 00 00 00', 'bursar@example.test'])
            ->assertSee('href="tel:+237670000000"', false);
    }

    public function test_a_note_is_kept_with_its_author_and_shown_on_the_school_page(): void
    {
        $this->as('education_secretary');

        $this->post(route('body.institutions.notes.store', $this->school), ['kind' => 'visit', 'noted_on' => now()->toDateString(), 'body' => 'Saw the PTA minutes; fees board up to date.'])
            ->assertRedirect();

        $note = InstitutionNote::sole();
        $this->assertSame($this->body->id, $note->body_id);
        $this->assertSame('Education secretary', $note->author->name);

        $this->get(route('body.institutions.show', $this->school))
            ->assertSeeInOrder(['Notes on this school', 'Visit', 'Education secretary', 'Saw the PTA minutes']);
    }

    public function test_an_auditor_reads_notes_but_cannot_write_one(): void
    {
        $this->as('auditor');

        $this->get(route('body.institutions.show', $this->school))->assertOk()->assertDontSee('Add a note');
        $this->post(route('body.institutions.notes.store', $this->school), ['kind' => 'note', 'noted_on' => now()->toDateString(), 'body' => 'Not allowed'])
            ->assertForbidden();
        $this->assertSame(0, InstitutionNote::count());
    }

    public function test_the_daybook_downloads_for_excel_and_the_download_is_recorded(): void
    {
        $filing = DaybookFiling::withoutGlobalScopes()->create([
            'body_id' => $this->body->id, 'institution_id' => $this->school->id, 'period' => '2026-10', 'sequence' => 1, 'is_current' => true,
            'currency' => 'XAF', 'money_in' => 150000, 'money_out' => 0, 'line_count' => 1, 'payload_hash' => str_repeat('a', 64),
        ]);
        DaybookLine::withoutGlobalScopes()->create([
            'daybook_filing_id' => $filing->id, 'body_id' => $this->body->id, 'institution_id' => $this->school->id,
            'line_date' => '2026-10-05', 'ref' => 'RCP-001', 'description' => 'Frais de scolarité', 'party' => 'Example Parent',
            'heading' => 'Tuition', 'kind' => 'fee', 'direction' => 'in', 'amount' => 150000, 'method' => 'cash', 'source_id' => 'p1',
        ]);

        $this->as('bursary_head');

        $this->get(route('body.institutions.daybook', ['institution' => $this->school, 'period' => '2026-10']))->assertSee('Download for Excel');

        $csv = $this->get(route('body.institutions.daybook.csv', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel reads accents only with the byte order mark.');
        $this->assertStringContainsString('RCP-001,"Frais de scolarité","Example Parent",Tuition,Fees,in,150000.00,Cash', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'daybook.exported', 'model_id' => $this->school->id]);
    }

    public function test_the_budget_sheet_downloads_and_a_month_with_no_sheet_is_not_found(): void
    {
        BudgetSheet::withoutGlobalScopes()->create([
            'body_id' => $this->body->id, 'institution_id' => $this->school->id, 'period' => '2026-10', 'sequence' => 1, 'is_current' => true,
            'currency' => 'XAF', 'budget_title' => '2026/2027', 'budget_starts' => '2026-09-01', 'budget_ends' => '2027-08-31',
            'income_budget' => 1000, 'income_to_date' => 200, 'expenditure_budget' => 800, 'expenditure_to_date' => 100,
            'payload' => json_encode(['totals' => [], 'lines' => [['code' => '611', 'name' => 'Tuition Fees', 'section' => 'income', 'heading' => false, 'budget' => '1000.00', 'month' => '200.00', 'to_date' => '200.00']]]),
            'payload_hash' => str_repeat('b', 64), 'generated_at' => now(), 'received_at' => now(),
        ]);

        $this->as('bursary_head');

        $csv = $this->get(route('body.institutions.budget.csv', ['institution' => $this->school, 'period' => '2026-10']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Income,611,"Tuition Fees",,1000.00,200.00,200.00,800.00', $csv);

        $this->get(route('body.institutions.budget.csv', ['institution' => $this->school, 'period' => '2026-09']))->assertNotFound();
    }

    public function test_the_staff_list_download_needs_the_staff_permission(): void
    {
        $this->as('institution_liaison');
        $this->get(route('body.institutions.staff.csv', ['institution' => $this->school, 'period' => '2026-10']))->assertForbidden();
    }

    public function test_the_activity_page_reads_the_record_in_plain_sentences(): void
    {
        $secretary = $this->as('education_secretary');
        AuditLog::log('staff.viewed', Institution::class, $this->school->id, null, ['period' => '2026-10'], $this->body->id);

        $this->as('auditor');

        $this->get(route('body.activity'))
            ->assertOk()
            ->assertSeeInOrder(['Education secretary', 'opened the staff list of St Jerome for October 2026']);

        $this->as('institution_liaison');
        $this->get(route('body.activity'))->assertForbidden();
    }

    public function test_to_look_into_can_be_narrowed_to_one_school(): void
    {
        $other = Institution::withoutBodyScope()->create(['body_id' => $this->body->id, 'name' => 'PAXHI', 'type' => 'school_higher', 'system' => 'paxhitestbed']);

        foreach ([[$this->school, 'Something at St Jerome'], [$other, 'Something at PAXHI']] as [$where, $title]) {
            Finding::withoutGlobalScopes()->create([
                'body_id' => $this->body->id, 'institution_id' => $where->id, 'rule' => 'test.'.$where->id, 'severity' => 'medium',
                'status' => Finding::OPEN, 'title' => $title, 'explanation' => 'Test', 'period' => '2026-10',
                'first_detected_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        $this->as('education_secretary');

        $this->get(route('body.findings.index', ['institution' => $other->uuid]))
            ->assertOk()
            ->assertSee('Something at PAXHI')
            ->assertDontSee('Something at St Jerome');
    }

    public function test_a_refused_page_explains_itself_and_offers_a_way_back(): void
    {
        $this->as('education_secretary');

        $this->get(route('body.manage.users.index'))
            ->assertForbidden()
            ->assertSee('Not allowed')
            ->assertSee('Back to the Overview');
    }

    public function test_printable_pages_offer_print(): void
    {
        $this->as('education_secretary');

        $this->get(route('body.institutions.show', $this->school))->assertSee('window.print()', false);
        $this->get(route('body.compliance.index'))->assertSee('window.print()', false);
    }
}
