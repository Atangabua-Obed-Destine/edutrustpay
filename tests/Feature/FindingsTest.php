<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\FindingEngine;
use App\Simulator\PushClient;
use App\Simulator\ScenarioGenerator;
use App\Support\BodyContext;
use EdutrustPay\Contract\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The findings engine.
 *
 * THIS IS WHAT THE SIMULATOR WAS BUILT FOR. Every control in brief §11 is a
 * claim that the platform will notice something; these tests make each thing
 * happen through the real ingest API and check the finding appears. Without
 * that, a green suite would only prove the rules never fire.
 */
class FindingsTest extends TestCase
{
    use RefreshDatabase;

    private Body $body;

    private Institution $institution;

    private PushClient $client;

    private ScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);
        $this->institution = $this->makeInstitution('St Bede College');

        $issued = InstitutionCredential::issue($this->institution, 'Test');
        $this->client = new PushClient($issued['credential'], $issued['secret']);
        $this->generator = new ScenarioGenerator(1);
    }

    // ---------------------------------------------------------------
    // Each scenario raises its control
    // ---------------------------------------------------------------

    public function test_an_unbalanced_ledger_raises_a_finding(): void
    {
        $this->pushScenario('unbalanced', 6, ScenarioGenerator::PROFILES['full']);

        $finding = $this->finding('unbalanced_ledger');

        $this->assertNotNull($finding, 'An unbalanced ledger must raise a finding.');
        $this->assertSame('critical', $finding->severity);

        // The explanation has to be readable by someone without an accounting
        // background — it is the entire interface between a control and a person.
        $this->assertStringContainsString('does not balance', strtolower($finding->title));
        $this->assertNotEmpty($finding->figures['difference']);
    }

    public function test_restating_a_closed_month_raises_a_critical_finding(): void
    {
        $this->pushScenario('restate_final', 6, [Capability::LEDGER, Capability::INTEGRITY]);

        $finding = $this->finding('final_restated');

        $this->assertNotNull($finding, 'Refiling a final period must raise a finding.');
        $this->assertSame('critical', $finding->severity);

        // Both versions stay visible, and the finding says how far the figure moved.
        $this->assertArrayHasKey('previous_sequence', $finding->figures);
        $this->assertArrayHasKey('new_sequence', $finding->figures);
        $this->assertGreaterThan($finding->figures['previous_sequence'], $finding->figures['new_sequence']);
    }

    public function test_a_provisional_month_refiled_when_it_closes_is_not_a_finding(): void
    {
        // Ordinary bookkeeping. Treating it as an incident would bury the real
        // signal under noise every month end.
        $provisional = $this->generator->build($this->institution, '2026-08', 0, 1, 'clean', [Capability::LEDGER]);
        $provisional['status'] = 'provisional';
        $this->client->pushReport($provisional);

        $final = $this->generator->build($this->institution, '2026-08', 0, 1, 'clean', [Capability::LEDGER], 2);
        $this->client->pushReport($final);

        $this->assertNull($this->finding('final_restated'));
    }

    public function test_a_collection_gap_raises_a_finding_that_states_its_own_caveat(): void
    {
        $this->pushScenario('collection_gap', 6, ScenarioGenerator::PROFILES['full']);

        $finding = $this->finding('collection_gap');

        $this->assertNotNull($finding);

        // expected_fees is the school's own number. The finding must say so, or
        // somebody will act on it as though it were arithmetic.
        $this->assertStringContainsString('own figure', $finding->explanation);
        $this->assertStringNotContainsString('pushes', $finding->explanation, 'The console never pushes anything into a school.');
        $this->assertNotSame('critical', $finding->severity);
        $this->assertArrayHasKey('shortfall', $finding->figures);
    }

    public function test_an_unposted_backlog_raises_a_finding(): void
    {
        $this->pushScenario('unposted_backlog', 6, [Capability::LEDGER]);

        $finding = $this->finding('unposted_backlog');

        $this->assertNotNull($finding);
        $this->assertGreaterThan(0, $finding->figures['unposted_count']);
    }

    public function test_a_clean_institution_raises_nothing(): void
    {
        $this->pushScenario('clean', 6, ScenarioGenerator::PROFILES['full']);

        // The baseline the others are read against. A findings engine that fires
        // on healthy data is worse than none.
        $this->assertSame(0, Finding::withoutBodyScope()->count());
    }

    public function test_backfilled_history_does_not_raise_backdated_findings(): void
    {
        // An institution connected today pushing last year's months. Every one
        // of those was assembled after it closed, by definition — raising a
        // finding per month would greet a correct onboarding with a wall of
        // noise. Observed for real: the paxhitestbed testbed produced eleven.
        $this->institution->forceFill(['created_at' => now()])->saveQuietly();

        $payload = $this->generator->build(
            $this->institution,
            now()->subMonths(8)->format('Y-m'),
            0, 1, 'clean',
            [Capability::LEDGER, Capability::INTEGRITY]
        );

        // Say plainly that the month was assembled long after it closed.
        $payload['integrity']['backdated_entries'] = 80;

        $this->client->pushReport($payload);

        $this->assertNull($this->finding('backdated_entries'));
    }

    public function test_a_recent_month_assembled_late_does_raise_a_finding(): void
    {
        // The other side of the same rule: this is not backfill, it is a month
        // that kept changing after it closed.
        $this->institution->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();

        $payload = $this->generator->build(
            $this->institution,
            now()->subMonth()->format('Y-m'),
            0, 1, 'clean',
            [Capability::LEDGER, Capability::INTEGRITY]
        );

        $payload['integrity']['backdated_entries'] = 80;

        $this->client->pushReport($payload);

        $this->assertNotNull($this->finding('backdated_entries'));
    }

    // ---------------------------------------------------------------
    // Silence
    // ---------------------------------------------------------------

    public function test_silence_raises_a_finding_only_after_the_threshold(): void
    {
        $engine = app(FindingEngine::class);

        $this->institution->forceFill([
            'last_report_at' => now()->subDays(10),
            'created_at' => now()->subMonths(6),
        ])->saveQuietly();

        $engine->sweep();
        $this->assertNull($this->finding('not_reporting'), 'Ten days is not silence.');

        $this->institution->forceFill(['last_report_at' => now()->subDays(60)])->saveQuietly();

        $engine->sweep();
        $this->assertNotNull($this->finding('not_reporting'));
    }

    public function test_never_having_reported_reads_differently_from_having_stopped(): void
    {
        $never = $this->makeInstitution('St Augustine College');
        $never->forceFill(['created_at' => now()->subMonths(6)])->saveQuietly();

        app(FindingEngine::class)->sweep();

        $finding = Finding::withoutBodyScope()->where('institution_id', $never->id)->first();

        $this->assertNotNull($finding);
        // A failed onboarding needs a different conversation from a lapse.
        $this->assertSame(__('Never reported'), $finding->title);
        $this->assertStringContainsString('onboarding', $finding->explanation);
    }

    public function test_the_sweep_does_not_pile_up_duplicates(): void
    {
        $this->institution->forceFill([
            'last_report_at' => now()->subDays(90),
            'created_at' => now()->subMonths(6),
        ])->saveQuietly();

        $engine = app(FindingEngine::class);

        // A nightly job that raised a new finding each night would fill the
        // console until nobody read it. MySQL's unique index cannot enforce this
        // because `period` is NULL here, so the engine has to.
        $engine->sweep();
        $engine->sweep();
        $engine->sweep();

        $this->assertSame(1, Finding::withoutBodyScope()->where('rule', 'not_reporting')->count());
        $this->assertSame(3, $this->finding('not_reporting')->occurrences);
    }

    public function test_resuming_reporting_closes_the_silence_finding_but_keeps_the_record(): void
    {
        $this->institution->forceFill([
            'last_report_at' => now()->subDays(90),
            'created_at' => now()->subMonths(6),
        ])->saveQuietly();

        $engine = app(FindingEngine::class);
        $engine->sweep();

        $this->assertSame(Finding::OPEN, $this->finding('not_reporting')->status);

        // Reporting resumes.
        $this->institution->forceFill(['last_report_at' => now()])->saveQuietly();
        $engine->sweep();

        $finding = $this->finding('not_reporting');

        $this->assertSame(Finding::EXPLAINED, $finding->status);
        // Nothing is deleted: the episode stays, and the note is explicit that
        // no person answered it.
        $this->assertNull($finding->resolved_by);
        $this->assertStringContainsString('not by a person', $finding->resolution_note);
        $this->assertDatabaseHas('sync_events', ['type' => SyncEvent::RECOVERED]);
    }

    // ---------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------

    public function test_the_same_condition_in_a_new_month_is_a_new_finding(): void
    {
        $this->pushScenario('unbalanced', 6, ScenarioGenerator::PROFILES['full']);

        // Same rule, different months, different figures — genuinely different
        // facts, and collapsing them would hide a problem that is still growing.
        $this->assertGreaterThan(
            1,
            Finding::withoutBodyScope()->where('rule', 'unbalanced_ledger')->count()
        );
    }

    public function test_an_answered_finding_is_not_reopened_by_the_machine(): void
    {
        $this->institution->forceFill([
            'last_report_at' => now()->subDays(90),
            'created_at' => now()->subMonths(6),
        ])->saveQuietly();

        $engine = app(FindingEngine::class);
        $engine->sweep();

        $user = User::create([
            'body_id' => $this->body->id, 'name' => 'Bursary Head',
            'email' => 'bursar@example.test', 'password' => 'password', 'role' => 'bursary_head',
        ]);

        $finding = $this->finding('not_reporting');
        $finding->resolve(Finding::ACCEPTED, $user, 'The school is closed for the long vacation.');

        // Overwriting a human decision with a machine's opinion every night is
        // the fastest way to teach people to ignore the console.
        $engine->sweep();

        $finding->refresh();
        $this->assertSame(Finding::ACCEPTED, $finding->status);
        $this->assertSame($user->id, $finding->resolved_by);

        // But the condition is still true, and last_seen_at says so.
        $this->assertGreaterThan(1, $finding->occurrences);
    }

    public function test_a_disabled_rule_does_not_fire(): void
    {
        config(['findings.rules.unbalanced_ledger.enabled' => false]);

        $this->pushScenario('unbalanced', 6, ScenarioGenerator::PROFILES['full']);

        $this->assertNull($this->finding('unbalanced_ledger'));
    }

    public function test_findings_are_body_scoped_like_everything_else(): void
    {
        $this->pushScenario('unbalanced', 6, ScenarioGenerator::PROFILES['full']);

        $otherBody = Body::create(['name' => 'Diocese of Buea', 'type' => 'diocese']);

        $this->actingAs(User::create([
            'body_id' => $otherBody->id, 'name' => 'Buea Head',
            'email' => 'buea@example.test', 'password' => 'password', 'role' => 'bursary_head',
        ]));
        BodyContext::flush();

        $this->assertSame(0, Finding::count());
    }

    public function test_the_sweep_command_runs(): void
    {
        $this->institution->forceFill([
            'last_report_at' => now()->subDays(90),
            'created_at' => now()->subMonths(6),
        ])->saveQuietly();

        $this->artisan('edutrustpay:sweep')->assertSuccessful();
        $this->assertNotNull($this->finding('not_reporting'));
    }

    public function test_the_sweep_dry_run_writes_nothing(): void
    {
        $this->institution->forceFill([
            'last_report_at' => now()->subDays(90),
            'created_at' => now()->subMonths(6),
        ])->saveQuietly();

        $this->artisan('edutrustpay:sweep', ['--dry-run' => true])->assertSuccessful();

        // Thresholds are a governance decision; somebody will want to see what
        // "35 days" catches before agreeing to it.
        $this->assertSame(0, Finding::withoutBodyScope()->count());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeInstitution(string $name): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $this->body->id,
            'name' => $name,
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
        ]);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function pushScenario(string $scenario, int $months, array $capabilities): void
    {
        $cursor = now()->startOfMonth()->subMonths($months);
        $periods = [];

        for ($i = 0; $i < $months; $i++) {
            $periods[] = $cursor->addMonth()->format('Y-m');
        }

        foreach ($periods as $i => $period) {
            $payload = $this->generator->build($this->institution, $period, $i, $months, $scenario, $capabilities);

            if ($payload !== null) {
                $this->client->pushReport($payload);
            }
        }

        if ($scenario === 'restate_final') {
            $target = $periods[max(0, count($periods) - 3)];
            $this->client->pushReport(
                $this->generator->build($this->institution, $target, 0, $months, 'restate_final', $capabilities, 2)
            );
        }
    }

    private function finding(string $rule): ?Finding
    {
        return Finding::withoutBodyScope()
            ->where('institution_id', $this->institution->id)
            ->where('rule', $rule)
            ->orderByDesc('id')
            ->first();
    }
}
