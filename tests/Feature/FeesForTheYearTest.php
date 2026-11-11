<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyInstitutionSignature as Sig;
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
 * A school's system sends its fees for the school year (contract 1.4): what is
 * expected, collected and still owed, class by class and fee by fee, and how
 * many students have paid. The Education Secretary sees it on the month, on
 * the school's page and side by side with the other schools on the Overview.
 */
class FeesForTheYearTest extends TestCase
{
    use RefreshDatabase;

    private Body $body;

    /** @var array<string, array{school: Institution, credential: InstitutionCredential, secret: string}> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);

        foreach (['SAHIK' => 'school_higher', 'St Jerome' => 'school_secondary'] as $name => $type) {
            $school = Institution::withoutBodyScope()->create(['body_id' => $this->body->id, 'name' => $name, 'type' => $type, 'system' => 'paxhitestbed']);
            $issued = InstitutionCredential::issue($school, 'Test');
            $this->schools[$name] = ['school' => $school, 'credential' => $issued['credential'], 'secret' => $issued['secret']];
        }
    }

    /** SAHIK sends its fees for the year. */
    private function yearReport(): array
    {
        $m = fn (string $v) => Money::of($v);

        return PayloadBuilder::for($this->schools['SAHIK']['school']->uuid, '2026-10')
            ->status('provisional')
            ->sequence(1)
            ->generatedAt(now()->toIso8601String())
            ->currency('XAF')
            ->fees('2026/2027', [
                ['level' => 'HND - NURSING', 'category' => 'First Installment', 'expected' => $m('3250000.00'), 'collected' => $m('2000000.00'), 'owed' => $m('1250000.00'), 'overpaid' => $m('0.00')],
                ['level' => 'HND - NURSING', 'category' => 'Admission Fees', 'expected' => $m('210000.00'), 'collected' => $m('225000.00'), 'owed' => $m('0.00'), 'overpaid' => $m('15000.00')],
                ['level' => 'HND - ACCOUNTANCY', 'category' => 'First Installment', 'expected' => $m('5800000.00'), 'collected' => $m('2900000.00'), 'owed' => $m('2900000.00'), 'overpaid' => $m('0.00')],
            ], ['paid' => 12, 'part_paid' => 20, 'not_paid' => 10, 'not_charged' => 2])
            ->build();
    }

    /** St Jerome's system is older: billed and collected since it started. */
    private function runningReport(): array
    {
        return PayloadBuilder::for($this->schools['St Jerome']['school']->uuid, '2026-10')
            ->status('provisional')
            ->sequence(1)
            ->generatedAt(now()->toIso8601String())
            ->currency('XAF')
            ->enrolment(42, Money::of('455000.00'), Money::of('300000.00'), '0.6593', '0.40')
            ->build();
    }

    private function push(string $name, array $payload): void
    {
        $school = $this->schools[$name];
        $json = Canonical::encode($payload);
        $timestamp = now()->toIso8601String();

        $this->call('POST', '/api/v1/reports', [], [], [], [
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::KEY_HEADER)) => $school['credential']->key_id,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::SIGNATURE_HEADER)) => Signer::signRaw($json, $timestamp, $school['secret']),
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertStatus(202);
    }

    private function asSecretary(): self
    {
        $user = User::create(['body_id' => $this->body->id, 'name' => 'Ed Sec', 'email' => 'es@example.test', 'password' => 'password', 'role' => 'education_secretary']);
        $user->roles()->sync([Role::where('name', 'education_secretary')->value('id')]);

        $this->actingAs($user);
        BodyContext::flush();

        return $this;
    }

    public function test_the_year_s_fees_are_kept_with_the_report(): void
    {
        $this->push('SAHIK', $this->yearReport());

        $report = PeriodReport::withoutBodyScope()->sole();

        $this->assertTrue($report->declares('fees'));
        $this->assertSame('2026/2027', $report->fee_session);
        $this->assertSame(['9260000.00', '5125000.00', '4150000.00', '15000.00'], [$report->fees_expected, $report->fees_collected, $report->fees_owed, $report->fees_overpaid]);
    }

    public function test_the_month_shows_the_year_by_class_and_by_fee_with_who_has_paid(): void
    {
        $this->push('SAHIK', $this->yearReport());

        $this->asSecretary()
            ->get(route('body.institutions.monthly', ['institution' => $this->schools['SAHIK']['school'], 'period' => '2026-10']))
            ->assertOk()
            ->assertSee('Fees for the 2026/2027 year')
            ->assertSeeInOrder(['Expected for the year', '9 260 000', 'Collected', '5 125 000', '55% of what is expected', 'Still owed', '4 150 000'], false)
            ->assertSeeInOrder(['Paid everything', '12', 'Paid part', '20', 'Paid nothing yet', '10', 'Enrolled, never charged', '2'])
            ->assertSee('2 students are enrolled for the year but have not been charged any fee')
            // By class: Nursing owes 1 250 000. Its admission overpayment of 15 000 does not reduce that.
            ->assertSeeInOrder(['By class', 'HND - ACCOUNTANCY', '2 900 000', 'HND - NURSING', '1 250 000'], false)
            ->assertSeeInOrder(['By fee', 'Admission Fees', 'First Installment', '4 150 000'], false)
            ->assertSee('This school sends its fees for the year instead');
    }

    public function test_the_school_page_leads_with_the_year(): void
    {
        $this->push('SAHIK', $this->yearReport());

        $this->asSecretary()
            ->get(route('body.institutions.show', ['institution' => $this->schools['SAHIK']['school'], 'period' => '2026-10']))
            ->assertOk()
            ->assertSee('Fees collected, 2026/2027')
            ->assertSee('55% of 9 260 000 expected')
            ->assertSee('Fees still owed, 2026/2027')
            ->assertSee('10 students have paid nothing yet');
    }

    public function test_the_overview_sets_the_schools_side_by_side_and_marks_the_older_figures(): void
    {
        $this->push('SAHIK', $this->yearReport());
        $this->push('St Jerome', $this->runningReport());

        $this->asSecretary()
            ->get(route('body.dashboard', ['period' => '2026-10']))
            ->assertOk()
            ->assertSee('Fees for the school year, as at the end of October 2026')
            ->assertSeeInOrder(['SAHIK', '2026/2027', '9 260 000', '5 125 000', '4 150 000', '55%'], false)
            // St Jerome's owed comes from its books, which it does not send.
            ->assertSeeInOrder(['St Jerome', '*', '455 000', '300 000'], false)
            ->assertSee('does not send its fees for the year yet');
    }

    public function test_a_school_that_never_sent_the_year_says_so_on_the_month(): void
    {
        $this->push('St Jerome', $this->runningReport());

        $this->asSecretary()
            ->get(route('body.institutions.monthly', ['institution' => $this->schools['St Jerome']['school'], 'period' => '2026-10']))
            ->assertOk()
            ->assertSee("St Jerome doesn't send its fees for the school year");
    }
}
