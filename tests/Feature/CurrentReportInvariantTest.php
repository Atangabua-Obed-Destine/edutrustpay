<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Support\BodyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * At most one report may stand for a period.
 *
 * Everything downstream — the consolidated position, the year-to-date
 * statement, the findings engine — resolves a period with
 * `where('is_current', true)->keyBy('period')`. With two current rows that
 * keyBy silently keeps whichever the driver happened to return last, so three
 * different pages can show three different answers for the same month and none
 * of them looks wrong.
 *
 * ReportIngestService already maintains this correctly. The point of these
 * tests is that nothing ELSE can break it, including code written later that
 * never reads the ingest service.
 */
class CurrentReportInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $body = Body::create([
            'name' => 'Archdiocese of Bamenda',
            'type' => 'archdiocese',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();

        $this->institution = Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => 'Catholic University of Bamenda',
            'type' => 'school_higher',
            'system' => 'paxhitestbed',
        ]);
    }

    public function test_a_second_current_report_for_the_same_period_is_refused(): void
    {
        $this->makeReport(sequence: 1, current: true);

        $this->expectException(QueryException::class);

        // The failure this prevents: a seeder, a backfill or a hand-repair
        // writing a row that bypasses ReportIngestService entirely.
        $this->makeReport(sequence: 2, current: true);
    }

    /**
     * Supersession must still work exactly as before: the earlier row keeps its
     * figures and its signature and stays visible, it merely stops standing.
     */
    public function test_superseded_versions_are_unconstrained_and_retained(): void
    {
        $first = $this->makeReport(sequence: 1, current: true);

        $first->forceFill(['is_current' => false])->saveQuietly();

        $second = $this->makeReport(sequence: 2, current: true);
        $third = $this->makeReport(sequence: 3, current: false);

        $this->assertSame(3, PeriodReport::withoutBodyScope()
            ->where('institution_id', $this->institution->id)->where('period', '2026-08')->count());

        $this->assertSame(1, PeriodReport::withoutBodyScope()
            ->where('institution_id', $this->institution->id)
            ->where('period', '2026-08')->where('is_current', true)->count());

        $this->assertSame($second->id, PeriodReport::withoutBodyScope()
            ->where('institution_id', $this->institution->id)
            ->where('period', '2026-08')->where('is_current', true)->value('id'));
    }

    /**
     * The constraint is per period, not per institution — an institution
     * obviously has one current report for each month it has reported.
     */
    public function test_different_periods_each_keep_their_own_current_report(): void
    {
        $this->makeReport(sequence: 1, current: true, period: '2026-07');
        $this->makeReport(sequence: 1, current: true, period: '2026-08');

        $this->assertSame(2, PeriodReport::withoutBodyScope()
            ->where('institution_id', $this->institution->id)->where('is_current', true)->count());
    }

    private function makeReport(int $sequence, bool $current, string $period = '2026-08'): PeriodReport
    {
        return PeriodReport::withoutBodyScope()->create([
            'institution_id' => $this->institution->id,
            'body_id' => $this->institution->body_id,
            'period' => $period,
            'sequence' => $sequence,
            'status' => 'provisional',
            'is_current' => $current,
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
    }
}
