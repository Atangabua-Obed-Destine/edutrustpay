<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\PeriodReport;
use App\Simulator\PushClient;
use App\Simulator\ScenarioGenerator;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The simulator.
 *
 * Its real job is to be the regression suite for the findings engine: every
 * control in brief §11 is a claim that the platform will notice something, and a
 * claim like that is worth nothing until the thing can be made to happen on
 * demand. These tests check that each scenario actually produces the condition
 * it advertises — otherwise a green findings suite would prove only that the
 * rules never fire.
 */
class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private ScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);

        $this->institution = Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => 'St Bede College',
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
        ]);

        $this->generator = new ScenarioGenerator(20260909);
    }

    public function test_every_scenario_produces_payloads_the_contract_accepts(): void
    {
        $validator = new Validator();

        foreach (array_keys(ScenarioGenerator::SCENARIOS) as $scenario) {
            foreach ($this->months(6) as $i => $period) {
                $payload = $this->generator->build(
                    $this->institution, $period, $i, 6, $scenario, ScenarioGenerator::PROFILES['full']
                );

                if ($payload === null) {
                    continue;
                }

                $result = $validator->validate($payload);

                $this->assertSame([], $result['errors'], "Scenario '$scenario' produced an invalid payload for $period.");
            }
        }
    }

    public function test_the_generator_is_deterministic(): void
    {
        // A findings regression test that generated different figures each run
        // would be a coin toss with extra steps.
        $a = (new ScenarioGenerator(42))->build($this->institution, '2026-08', 0, 6, 'clean', ScenarioGenerator::PROFILES['full']);
        $b = (new ScenarioGenerator(42))->build($this->institution, '2026-08', 0, 6, 'clean', ScenarioGenerator::PROFILES['full']);

        $this->assertEquals($a, $b);
    }

    public function test_a_profile_limits_what_is_reported_rather_than_zeroing_it(): void
    {
        $payload = $this->generator->build(
            $this->institution, '2026-08', 0, 6, 'clean', ScenarioGenerator::PROFILES['ledger-only']
        );

        $this->assertSame([Capability::LEDGER], $payload['capabilities']);

        // Absent, not zero. This is the whole point of profiles existing.
        $this->assertArrayNotHasKey('receivables', $payload['financial']);
        $this->assertArrayNotHasKey('operational', $payload);
    }

    // ---------------------------------------------------------------
    // Each scenario produces the condition it advertises
    // ---------------------------------------------------------------

    public function test_silent_stops_reporting_entirely(): void
    {
        $sent = 0;

        foreach ($this->months(8) as $i => $period) {
            if ($this->generator->build($this->institution, $period, $i, 8, 'silent', [Capability::LEDGER]) !== null) {
                $sent++;
            }
        }

        // Silence is the absence of a report, not a report saying nothing —
        // modelling it any other way would defeat the control it exercises.
        $this->assertSame(4, $sent);
    }

    public function test_unbalanced_makes_debits_stop_equalling_credits(): void
    {
        $later = $this->generator->build($this->institution, '2026-08', 4, 6, 'unbalanced', [Capability::LEDGER]);

        $control = $later['financial']['control'];

        $this->assertFalse(
            $control['total_debits']->equals($control['total_credits']),
            'The unbalanced scenario must actually unbalance the ledger.'
        );
    }

    public function test_ghost_students_raises_enrolment_while_income_stays_flat(): void
    {
        $first = $this->generator->build($this->institution, '2026-04', 0, 6, 'ghost_students', ScenarioGenerator::PROFILES['full']);
        $last = $this->generator->build($this->institution, '2026-09', 5, 6, 'ghost_students', ScenarioGenerator::PROFILES['full']);

        $this->assertGreaterThan(
            $first['operational']['enrolment'],
            $last['operational']['enrolment'],
            'Students should be arriving.'
        );

        // And the money owed grows with them, which is what makes the gap visible.
        $this->assertSame(1, $last['operational']['expected_fees']->compare($first['operational']['expected_fees']));
    }

    public function test_cash_creep_increases_the_physical_cash_share(): void
    {
        $first = $this->generator->build($this->institution, '2026-04', 0, 6, 'cash_creep', ScenarioGenerator::PROFILES['full']);
        $last = $this->generator->build($this->institution, '2026-09', 5, 6, 'cash_creep', ScenarioGenerator::PROFILES['full']);

        $this->assertGreaterThan(
            (float) $first['operational']['cash_share'],
            (float) $last['operational']['cash_share']
        );
    }

    public function test_arrears_spike_grows_the_ninety_day_bucket(): void
    {
        $first = $this->generator->build($this->institution, '2026-04', 0, 6, 'arrears_spike', ScenarioGenerator::PROFILES['full']);
        $last = $this->generator->build($this->institution, '2026-09', 5, 6, 'arrears_spike', ScenarioGenerator::PROFILES['full']);

        // Cash received and never entered has to be explained as something. It
        // usually gets explained as debt.
        $this->assertSame(
            1,
            $last['financial']['receivables']['ageing']['90_plus']
                ->compare($first['financial']['receivables']['ageing']['90_plus'])
        );
    }

    public function test_unposted_backlog_accumulates(): void
    {
        $first = $this->generator->build($this->institution, '2026-04', 0, 6, 'unposted_backlog', [Capability::LEDGER]);
        $last = $this->generator->build($this->institution, '2026-09', 5, 6, 'unposted_backlog', [Capability::LEDGER]);

        $this->assertGreaterThan(
            $first['financial']['control']['unposted_count'],
            $last['financial']['control']['unposted_count']
        );
    }

    public function test_collection_gap_drives_the_rate_below_the_threshold(): void
    {
        $last = $this->generator->build($this->institution, '2026-09', 5, 6, 'collection_gap', ScenarioGenerator::PROFILES['full']);

        $this->assertLessThan(
            (float) config('findings.rules.collection_gap.min_collection_rate'),
            (float) $last['operational']['collection_rate']
        );
    }

    // ---------------------------------------------------------------
    // The push path
    // ---------------------------------------------------------------

    public function test_the_push_client_goes_through_the_real_signature_middleware(): void
    {
        $issued = InstitutionCredential::issue($this->institution, 'Test');
        $client = new PushClient($issued['credential'], $issued['secret']);

        $payload = $this->generator->build($this->institution, '2026-08', 0, 6, 'clean', [Capability::LEDGER]);
        $result = $client->pushReport($payload);

        $this->assertSame(202, $result['status']);
        $this->assertSame(1, PeriodReport::withoutBodyScope()->count());
    }

    public function test_a_push_with_the_wrong_secret_is_refused_like_any_other_client(): void
    {
        $issued = InstitutionCredential::issue($this->institution, 'Test');

        // Nothing about the simulator bypasses authentication — if it did, it
        // would be proving something other than what the API actually does.
        $client = new PushClient($issued['credential'], 'not-the-secret');

        $payload = $this->generator->build($this->institution, '2026-08', 0, 6, 'clean', [Capability::LEDGER]);

        $this->assertSame(401, $client->pushReport($payload)['status']);
        $this->assertSame(0, PeriodReport::withoutBodyScope()->count());
    }

    public function test_the_push_command_runs_end_to_end(): void
    {
        InstitutionCredential::issue($this->institution, 'Test');

        $this->artisan('edutrustpay:simulate:push', [
            'institution' => $this->institution->slug,
            '--months' => 3,
            '--scenario' => 'clean',
            '--profile' => 'ledger-only',
        ])->assertSuccessful();

        $this->assertSame(3, PeriodReport::withoutBodyScope()->count());
    }

    public function test_an_unknown_scenario_is_refused_rather_than_silently_ignored(): void
    {
        $this->artisan('edutrustpay:simulate:push', ['--scenario' => 'wishful-thinking'])->assertFailed();
    }

    /**
     * @return array<int, string>
     */
    private function months(int $count): array
    {
        $cursor = now()->startOfMonth()->subMonths($count);

        return collect(range(1, $count))
            ->map(fn () => ($cursor = $cursor->addMonth())->format('Y-m'))
            ->all();
    }
}
