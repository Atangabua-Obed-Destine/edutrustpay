<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Models\ReportLine;
use App\Models\Role;
use App\Models\User;
use App\Services\AnnualReturnService;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The twelve-month statement.
 *
 * The arithmetic is the easy half. The half worth testing is what happens when
 * a month is missing: an institution's own daybook always has all of its own
 * months and so never has to ask, but the console does, and a cumulative that
 * quietly skipped an unreported month would be a confident wrong number —
 * exactly what this platform exists to prevent.
 */
class AnnualReturnTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private AnnualReturnService $annual;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $body = Body::create([
            'name' => 'Archdiocese of Bamenda',
            'type' => 'archdiocese',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            // January-December, so a period in August is the eighth month.
            'fiscal_year_start_month' => 1,
        ])->fresh();

        $this->institution = Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => 'Catholic University of Bamenda',
            'type' => 'school_higher',
            'system' => 'paxhitestbed',
        ]);

        $this->annual = app(AnnualReturnService::class);
    }

    public function test_cumulative_is_the_sum_of_the_months_reported(): void
    {
        $this->report('2026-06', income: '100000.00');
        $this->report('2026-07', income: '200000.00');
        $this->report('2026-08', income: '300000.00');

        $statement = $this->annual->build($this->institution, '2026-08');

        $row = $statement['sections']['income']['rows'][0];

        $this->assertSame('300000.00', $row->month->money()->value(), 'This month stands alone.');
        $this->assertSame('300000.00', $row->brought->value(), 'Brought forward is June plus July.');
        $this->assertSame('600000.00', $row->cumulative->value());
    }

    /**
     * The point of the whole exercise.
     */
    public function test_a_missing_month_is_named_rather_than_silently_skipped(): void
    {
        // A full year to date except one month, so the gap is unambiguous:
        // every other elapsed month reported 100,000.
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->report($month, income: '100000.00');
        }

        // 2026-07 never arrived.
        $this->report('2026-08', income: '300000.00');

        $statement = $this->annual->build($this->institution, '2026-08');

        $this->assertSame(['2026-07'], $statement['missing']);
        $this->assertFalse($statement['complete']);

        $sentence = $this->annual->coverageStatement(
            count($statement['elapsed']),
            $statement['missing']
        );

        $this->assertStringContainsString('2026-07', $sentence, 'The missing month is named, not merely counted.');
        $this->assertStringContainsString('7 of 8', $sentence);

        // And the total is still only what was actually reported — a floor,
        // stated as such, never a guess at what July might have been.
        $this->assertSame('900000.00', $statement['sections']['income']['totals']->cumulative->value());
    }

    /**
     * A month that never arrived is not a month of zero, and the difference has
     * to survive all the way to the rendered page.
     */
    public function test_the_current_month_reads_as_not_reported_when_it_never_arrived(): void
    {
        $this->report('2026-06', income: '100000.00');

        $statement = $this->annual->build($this->institution, '2026-08');

        $row = $statement['sections']['income']['rows'][0];

        $this->assertFalse($row->month->hasValue());
        $this->assertTrue($row->month->isMissing());
        $this->assertSame('100000.00', $row->cumulative->value());
    }

    /**
     * The Archdiocese runs January-December while its schools run July-June, so
     * "the year to date" is two different windows in the same building.
     */
    public function test_the_fiscal_year_follows_the_bodys_own_setting(): void
    {
        $this->institution->body->update(['fiscal_year_start_month' => 7]);
        $this->institution->refresh()->load('body');

        // Under a July year, January belongs to the year that opened last July.
        $months = $this->annual->fiscalMonths($this->institution, '2026-01');

        $this->assertSame('2025-07', $months[0]);
        $this->assertSame('2026-06', $months[11]);
        $this->assertCount(12, $months);
    }

    /**
     * The real case this exists for.
     *
     * The Archdiocese of Bamenda keeps calendar books. PAX HIGHER INSTITUTE's
     * budget 22 — the sheet its Bursar actually works from — runs 2025-10-01 to
     * 2026-08-31. Assembling PAX's year on the Archdiocese's January window
     * produced a statement that reconciles against nothing either party
     * publishes, which is harder to notice than a wrong number because every
     * figure in it is correct.
     */
    public function test_an_institution_uses_its_own_fiscal_year_not_the_bodys(): void
    {
        // The body keeps calendar books.
        $this->institution->body->update(['fiscal_year_start_month' => 1]);

        // The school's year opens in October.
        $this->institution->update(['fiscal_year_start_month' => 10]);
        $this->institution->refresh()->load('body');

        $months = $this->annual->fiscalMonths($this->institution, '2026-08');

        $this->assertSame('2025-10', $months[0], 'The school year opens in October, while the archdiocese keeps calendar books.');
        $this->assertSame('2026-09', $months[11]);
    }

    public function test_an_institution_without_its_own_year_inherits_the_bodys(): void
    {
        $this->institution->body->update(['fiscal_year_start_month' => 7]);
        $this->institution->update(['fiscal_year_start_month' => null]);
        $this->institution->refresh()->load('body');

        $this->assertSame(7, $this->institution->fiscalYearStartMonth());
        $this->assertSame('2025-07', $this->annual->fiscalMonths($this->institution, '2026-01')[0]);
    }

    /**
     * The window decides which months are counted, so it decides what counts as
     * missing — the one thing on that page nobody should have to second-guess.
     */
    public function test_the_window_changes_which_months_count_as_elapsed(): void
    {
        $this->institution->update(['fiscal_year_start_month' => 10]);
        $this->institution->refresh()->load('body');

        $this->report('2026-08', income: '300000.00');

        $statement = $this->annual->build($this->institution, '2026-08');

        // October through August inclusive is eleven months, ten of them absent.
        $this->assertCount(11, $statement['elapsed']);
        $this->assertCount(10, $statement['missing']);
        $this->assertContains('2025-10', $statement['missing']);
    }

    public function test_months_that_have_not_happened_yet_are_not_counted_as_missing(): void
    {
        $this->report('2026-01', income: '100000.00');

        $statement = $this->annual->build($this->institution, '2026-01');

        $this->assertSame(['2026-01'], $statement['elapsed']);
        $this->assertSame([], $statement['missing']);
        $this->assertTrue($statement['complete'], 'A year one month old is not eleven months late.');
    }

    public function test_the_page_renders_and_states_its_coverage(): void
    {
        $this->report('2026-06', income: '100000.00');
        $this->report('2026-08', income: '300000.00');

        $user = User::create([
            'name' => 'Bursary Head',
            'email' => 'bursar@example.test',
            'password' => 'password',
            'role' => 'body_user',
            'body_id' => $this->institution->body_id,
        ]);

        if ($role = Role::where('name', 'bursary_head')->first()) {
            $user->roles()->attach($role->id);
        }

        $this->actingAs($user);
        BodyContext::flush();

        $this->get(route('body.institutions.annual', $this->institution).'?period=2026-08')
            ->assertOk()
            ->assertSee('Year to date covers')
            ->assertSee('2026-07');
    }

    private function report(string $period, string $income): PeriodReport
    {
        $report = PeriodReport::withoutBodyScope()->create([
            'institution_id' => $this->institution->id,
            'body_id' => $this->institution->body_id,
            'period' => $period,
            'sequence' => 1,
            'status' => 'final',
            'is_current' => true,
            'contract_version' => '1.0',
            'currency' => 'XAF',
            'capabilities' => ['ledger'],
            'payload' => '{}',
            'payload_hash' => hash('sha256', '{}'),
            'signature' => str_repeat('a', 64),
            'key_id' => 'etp_test',
            'generated_at' => now(),
            'received_at' => now(),
        ]);

        ReportLine::create([
            'period_report_id' => $report->id,
            'section' => 'income',
            'ohada_group' => '70',
            'amount' => $income,
        ]);

        return $report;
    }
}
