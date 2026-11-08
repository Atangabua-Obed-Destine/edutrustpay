<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\PeriodReport;
use App\Models\User;
use App\Services\FindingEngine;
use App\Support\BodyContext;
use App\Support\Figure;
use EdutrustPay\Contract\Fingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faults found by driving the real systems end to end on real school data.
 * Each of these passed the existing suite while being wrong on screen.
 */
class EndToEndRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $body = Body::create([
            'name' => 'Diocese of Bamenda',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();

        $this->institution = Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => 'PAXHI',
            'type' => 'school_higher',
            'system' => 'paxhitestbed',
        ]);
    }

    public function test_a_total_nobody_reported_is_not_shown_as_zero(): void
    {
        $nothing = Figure::sum(['PAXHI' => Figure::notReported()]);

        $this->assertFalse($nothing->hasFigures());
        $this->assertSame(__('no figures yet'), $nothing->formatted());
    }

    public function test_the_dashboard_for_an_empty_month_prints_no_zero_total(): void
    {
        $user = User::create([
            'name' => 'Diocese Administrator',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'body_admin',
            'body_id' => $this->institution->body_id,
        ]);

        $this->actingAs($user);
        BodyContext::flush();

        $this->get(route('body.dashboard').'?period=2020-01')
            ->assertOk()
            ->assertSee(__('no report'))
            ->assertDontSee('>0<', false);
    }

    /**
     * The operator belongs to no body, so the body console is not theirs to
     * open directly. Seeing it as a body's people do is what View as is for.
     */
    public function test_the_operator_cannot_open_the_body_console_directly(): void
    {
        $operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'body_id' => null,
        ]);

        $this->actingAs($operator);
        BodyContext::flush();

        $this->get(route('body.dashboard'))->assertRedirect(route('admin.bodies.index'));
        $this->get('/')->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.bodies.impersonate', $this->institution->body_id));
        BodyContext::flush();

        $this->get(route('body.dashboard'))->assertOk();
    }

    public function test_each_side_sees_only_its_own_menu(): void
    {
        $operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'body_id' => null,
        ]);

        $this->actingAs($operator);
        BodyContext::flush();

        $this->get(route('admin.dashboard'))
            ->assertSee('Platform overview')
            ->assertDontSee('Consolidated position');

        $user = User::create([
            'name' => 'Diocese Administrator',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'body_admin',
            'body_id' => $this->institution->body_id,
        ]);

        $this->actingAs($user);
        BodyContext::flush();

        $this->get(route('body.dashboard'))
            ->assertSee('Diocese of Bamenda')
            ->assertDontSee('Platform overview');

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_a_real_zero_still_prints_as_zero(): void
    {
        $zero = Figure::sum(['PAXHI' => Figure::reported(\EdutrustPay\Contract\Money::zero())]);

        $this->assertSame('0', $zero->formatted());
    }

    public function test_rotating_a_key_keeps_the_old_one_working_then_retires_it(): void
    {
        $original = InstitutionCredential::issue($this->institution, 'first')['credential'];

        $original->rotate();
        $original->refresh();

        $this->assertTrue($original->isActive(), 'A replaced key must keep working during its grace period.');
        $this->assertTrue($original->isInGracePeriod());
        $this->assertSame(0, InstitutionCredential::expireRotated(), 'Too early to retire it.');

        $this->travel(InstitutionCredential::GRACE_HOURS + 1)->hours();

        $this->assertSame(1, InstitutionCredential::expireRotated());
        $this->assertFalse($original->fresh()->isActive());
    }

    public function test_the_sweep_retires_expired_keys(): void
    {
        $original = InstitutionCredential::issue($this->institution, 'first')['credential'];
        $original->rotate();

        $this->travel(InstitutionCredential::GRACE_HOURS + 1)->hours();

        $this->artisan('edutrustpay:sweep')->assertSuccessful();

        $this->assertFalse($original->fresh()->isActive());
    }

    public function test_a_fingerprint_ignores_what_moves_by_itself_and_catches_what_does_not(): void
    {
        $payload = [
            'status' => 'provisional',
            'sequence' => 1,
            'generated_at' => '2026-09-10T07:00:00Z',
            'financial' => [
                'income_by_group' => ['71' => '100.00'],
                'receivables' => ['total' => '10.00'],
            ],
            'integrity' => ['ledger_hash' => 'sha256:a', 'audit_events' => 5],
        ];

        $later = $payload;
        $later['generated_at'] = '2026-11-07T07:00:00Z';
        $later['sequence'] = 2;
        $later['financial']['receivables']['total'] = '99.00';
        $later['integrity']['audit_events'] = 900;

        $this->assertSame(Fingerprint::of($payload), Fingerprint::of($later));

        $edited = $payload;
        $edited['integrity']['ledger_hash'] = 'sha256:b';

        $this->assertNotSame(Fingerprint::of($payload), Fingerprint::of($edited));
    }

    public function test_an_old_provisional_month_with_changed_figures_raises_a_finding(): void
    {
        [$first, $second] = $this->restatement(
            ['ledger_hash' => 'sha256:a', 'surplus_deficit' => '100.00', 'journal_entry_count' => 10],
            ['ledger_hash' => 'sha256:b', 'surplus_deficit' => '900.00', 'journal_entry_count' => 90],
        );

        $finding = $this->evaluate($second);

        $this->assertNotNull($finding);
        $this->assertSame('high', $finding->severity);
    }

    public function test_re_created_entries_with_the_same_totals_are_a_lesser_finding(): void
    {
        [$first, $second] = $this->restatement(
            ['ledger_hash' => 'sha256:a', 'surplus_deficit' => '100.00', 'journal_entry_count' => 10],
            ['ledger_hash' => 'sha256:b', 'surplus_deficit' => '100.00', 'journal_entry_count' => 10],
        );

        $this->assertSame('medium', $this->evaluate($second)?->severity);
    }

    public function test_a_month_first_filed_before_it_ended_was_not_changed_afterwards(): void
    {
        [$first, $second] = $this->restatement(
            ['ledger_hash' => 'sha256:a', 'surplus_deficit' => '100.00', 'journal_entry_count' => 3],
            ['ledger_hash' => 'sha256:b', 'surplus_deficit' => '900.00', 'journal_entry_count' => 39],
        );

        // Filed part way through the month, so the rest of the month was still to come.
        $first->forceFill(['generated_at' => Carbon::createFromFormat('!Y-m', $first->period)->addDays(9)])->save();
        $second->unsetRelation('supersedes');

        $this->assertNull($this->evaluate($second));
    }

    public function test_a_rule_judges_its_own_severity_unless_told_otherwise(): void
    {
        foreach (config('findings.rules') as $code => $rule) {
            $this->assertArrayNotHasKey('severity', $rule, "$code would override what the rule itself decides.");
        }
    }

    public function test_a_month_is_read_correctly_on_the_31st(): void
    {
        $this->travelTo('2026-10-31 10:00:00');

        $this->assertSame('February 2026', period_text('2026-02'));

        $this->travelBack();
    }

    public function test_a_change_soon_after_month_end_is_ordinary_bookkeeping(): void
    {
        [$first, $second] = $this->restatement(
            ['ledger_hash' => 'sha256:a', 'surplus_deficit' => '100.00'],
            ['ledger_hash' => 'sha256:b', 'surplus_deficit' => '900.00'],
            generatedAt: '2026-08-10 00:00:00',
        );

        $this->assertNull($this->evaluate($second));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{0: PeriodReport, 1: PeriodReport}
     */
    private function restatement(array $old, array $new, string $generatedAt = '2026-11-07 00:00:00'): array
    {
        $first = $this->report(1, $old + ['is_current' => false, 'generated_at' => '2026-08-02 00:00:00']);
        $second = $this->report(2, $new + ['is_current' => true, 'generated_at' => $generatedAt, 'supersedes_id' => $first->id]);

        return [$first, $second];
    }

    private function evaluate(PeriodReport $report): ?Finding
    {
        app(FindingEngine::class)->evaluateReport($report->fresh());

        return Finding::withoutBodyScope()->where('rule', 'final_restated')->where('period', $report->period)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(int $sequence, array $attributes): PeriodReport
    {
        return PeriodReport::withoutBodyScope()->create($attributes + [
            'institution_id' => $this->institution->id,
            'body_id' => $this->institution->body_id,
            'period' => '2026-07',
            'sequence' => $sequence,
            'status' => 'provisional',
            'contract_version' => '1.0',
            'currency' => 'XAF',
            'capabilities' => ['ledger', 'integrity'],
            'payload' => '{}',
            'payload_hash' => hash('sha256', '{}'),
            'signature' => str_repeat('a', 64),
            'key_id' => 'etp_test',
            'received_at' => now(),
        ]);
    }
}
