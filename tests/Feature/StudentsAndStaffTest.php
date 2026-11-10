<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyInstitutionSignature as Sig;
use App\Models\AuditLog;
use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\PeriodReport;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\PayloadBuilder;
use EdutrustPay\Contract\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A school's system sends how many students each class holds and who is on its
 * staff; the console shows the counts on the month and the names on a page of
 * their own, to the roles allowed to see them, and records every opening.
 */
class StudentsAndStaffTest extends TestCase
{
    use RefreshDatabase;

    private Body $body;

    private Institution $school;

    private InstitutionCredential $credential;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);
        $this->school = Institution::withoutBodyScope()->create([
            'body_id' => $this->body->id,
            'name' => 'St Jerome',
            'type' => 'school_secondary',
            'system' => 'edutrustshsm',
        ]);

        $issued = InstitutionCredential::issue($this->school, 'Test');
        $this->credential = $issued['credential'];
        $this->secret = $issued['secret'];
    }

    private function report(string $period = '2026-10', int $sequence = 1): array
    {
        return PayloadBuilder::for($this->school->uuid, $period)
            ->status('provisional')
            ->sequence($sequence)
            ->generatedAt(now()->toIso8601String())
            ->currency('XAF')
            ->enrolment(42, Money::of('4200000.00'), Money::of('2100000.00'), '0.50', '0.40')
            ->students('2026/2027', [['level' => 'Form 1', 'students' => 25], ['level' => 'Form 2', 'students' => 17]])
            ->staff([
                ['number' => 'STF001', 'name' => 'Example Teacher', 'department' => 'Sciences', 'designation' => 'Teacher'],
                ['number' => 'STF002', 'name' => 'Example Principal', 'department' => 'Administration', 'designation' => 'Principal'],
                ['number' => null, 'name' => 'Example Watchman', 'department' => null, 'designation' => null],
            ])
            ->build();
    }

    private function push(array $payload): void
    {
        $json = Canonical::encode($payload);
        $timestamp = now()->toIso8601String();

        $this->call('POST', '/api/v1/reports', [], [], [], [
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::KEY_HEADER)) => $this->credential->key_id,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::SIGNATURE_HEADER)) => Signer::signRaw($json, $timestamp, $this->secret),
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertStatus(202);
    }

    private function as(string $role): self
    {
        $user = User::create([
            'body_id' => $this->body->id,
            'name' => ucfirst($role),
            'email' => $role.'@example.test',
            'password' => 'password',
            'role' => $role,
            'institution_id' => $role === 'institution_liaison' ? $this->school->id : null,
        ]);
        $user->roles()->sync([Role::where('name', $role)->value('id')]);

        $this->actingAs($user);
        BodyContext::flush();

        return $this;
    }

    public function test_a_report_with_students_and_staff_is_accepted_and_kept(): void
    {
        $this->push($this->report());

        $report = PeriodReport::withoutBodyScope()->sole();
        $this->assertTrue($report->declares('students'));
        $this->assertTrue($report->declares('staff'));
        $this->assertSame(42, $report->decodedPayload()['operational']['students']['total']);
        $this->assertSame(3, $report->decodedPayload()['operational']['staff']['total']);
    }

    public function test_the_month_shows_students_per_class_and_staff_by_department_and_post_without_names(): void
    {
        $this->push($this->report());

        $this->as('education_secretary')
            ->get(route('body.institutions.monthly', [$this->school, '2026-10']))
            ->assertOk()
            ->assertSeeInOrder(['Students per class', 'The 2026/2027 session', 'Form 1', '25', 'Form 2', '17', 'All classes', '42'])
            ->assertSeeInOrder(['Staff', '3 people on the books', 'By department', 'Administration', 'Sciences', 'Not recorded', 'By post', 'Principal', 'Teacher'])
            ->assertSee('See the staff list for October 2026, with names')
            // Counts only on the month; the names live on their own page.
            ->assertDontSee('Example Teacher');
    }

    public function test_the_staff_list_names_people_and_records_who_opened_it(): void
    {
        $this->push($this->report());

        $this->as('education_secretary')
            ->get(route('body.institutions.staff', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertOk()
            ->assertSeeInOrder(['Example Principal', 'Example Teacher', 'Example Watchman'])
            ->assertSee('STF001')
            ->assertSee('not recorded');

        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.viewed', 'model_id' => $this->school->id]);

        $this->get(route('body.institutions.staff', ['institution' => $this->school, 'period' => '2026-10', 'department' => 'Sciences']))
            ->assertSee('Example Teacher')
            ->assertDontSee('Example Principal');
    }

    public function test_only_the_roles_allowed_to_see_names_can_open_the_staff_list(): void
    {
        $this->push($this->report());

        foreach (['bursary_head', 'auditor', 'body_admin'] as $role) {
            $this->as($role)->get(route('body.institutions.staff', $this->school))->assertOk();
        }

        // A liaison sees one school's reports, and the counts, but not the names.
        $this->as('institution_liaison')
            ->get(route('body.institutions.staff', $this->school))
            ->assertForbidden();
        $this->get(route('body.institutions.monthly', [$this->school, '2026-10']))
            ->assertOk()
            ->assertSee('3 people on the books')
            ->assertSee('The list of names is shown only to people allowed to see it.')
            ->assertDontSee('Example Teacher');
    }

    public function test_a_school_that_sends_neither_says_so_rather_than_showing_zero(): void
    {
        $this->push(PayloadBuilder::for($this->school->uuid, '2026-10')
            ->status('provisional')->sequence(1)->generatedAt(now()->toIso8601String())->currency('XAF')
            ->enrolment(42, Money::of('4200000.00'), Money::of('2100000.00'), '0.50', '0.40')
            ->build());

        $this->as('education_secretary')
            ->get(route('body.institutions.monthly', [$this->school, '2026-10']))
            ->assertOk()
            ->assertSee('St Jerome doesn&#039;t send how many students each class has', false)
            ->assertSee('St Jerome doesn&#039;t send who is on its staff', false);

        $this->get(route('body.institutions.staff', $this->school))
            ->assertOk()
            ->assertSee('Its system does not send its staff yet');
    }

    public function test_what_schools_send_shows_the_two_new_parts(): void
    {
        $this->push($this->report());

        $this->as('education_secretary')
            ->get(route('body.capabilities'))
            ->assertOk()
            ->assertSee('Students per class')
            ->assertSee('Staff');
    }
}
