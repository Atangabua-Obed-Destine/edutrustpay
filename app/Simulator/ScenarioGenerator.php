<?php

namespace App\Simulator;

use App\Models\Institution;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\PayloadBuilder;
use Illuminate\Support\Carbon;

/**
 * Generates months of plausible reports, including deliberately fraudulent ones.
 *
 * This exists for two reasons, and the second matters more than the first.
 *
 * 1. The console has to be built against realistic multi-month data, and waiting
 *    for three real deployments to accumulate a year of history is not a plan.
 *
 * 2. THE SCENARIOS ARE THE REGRESSION SUITE FOR THE FINDINGS ENGINE. Every
 *    control in brief §11 is a claim about something the platform will notice.
 *    A claim like that is worth nothing until you can make the thing happen on
 *    demand and watch the finding appear. `--scenario=ghost_students` is an
 *    executable version of "enrolment up, fee income flat — off-book students".
 *
 * The figures are deterministic given a seed, so a scenario reproduces exactly.
 * That is the difference between a demo and a test.
 */
class ScenarioGenerator
{
    public const SCENARIOS = [
        'clean' => 'Everything in order. The baseline the others are read against.',
        'silent' => 'Reports normally, then stops. Should raise not_reporting.',
        'restate_final' => 'Refiles a closed month with lower income. Should raise final_restated.',
        'unbalanced' => 'Debits stop equalling credits. Should be accepted and raise unbalanced_ledger.',
        'collection_gap' => 'Fees collected fall far below what enrolment implies. Fraud models C and D.',
        'cash_creep' => 'The share taken as physical cash climbs month on month. Exposure to interception.',
        'ghost_students' => 'Enrolment rises while fee income stays flat. Students attending but never billed.',
        'arrears_spike' => 'The 90+ ageing bucket jumps. Cash never entered, disguised as debt.',
        'unposted_backlog' => 'Unposted entries accumulate. Figures kept out of the ledger.',
    ];

    /** Capability profiles matching what each real system can honestly send. */
    public const PROFILES = [
        'full' => [
            Capability::LEDGER, Capability::RECEIVABLES, Capability::PAYABLES,
            Capability::BUDGET, Capability::PAYROLL, Capability::INTEGRITY, Capability::ENROLMENT,
        ],
        'ledger-only' => [Capability::LEDGER],
        'posinnova-shaped' => [Capability::LEDGER, Capability::RECEIVABLES],
    ];

    private int $seed;

    public function __construct(?int $seed = null)
    {
        $this->seed = $seed ?? 20260909;
    }

    /**
     * Build one month's payload.
     *
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>|null  null when the scenario means "send nothing"
     */
    public function build(
        Institution $institution,
        string $period,
        int $monthIndex,
        int $totalMonths,
        string $scenario,
        array $capabilities,
        int $sequence = 1
    ): ?array {
        // "silent" is the absence of a report, not a report saying nothing.
        // Modelling it any other way would defeat the point.
        if ($scenario === 'silent' && $monthIndex >= (int) floor($totalMonths / 2)) {
            return null;
        }

        $r = $this->rng($institution->id, $period);

        // A plausible seasonal shape: fee income spikes at the start of terms.
        $month = (int) substr($period, 5, 2);
        $termStart = in_array($month, [9, 1, 4], true);
        $base = $termStart ? 9_500_000 : 3_200_000;

        $income = $this->money($base * (0.85 + $r(30) / 100));
        $otherIncome = $this->money(150_000 * (0.5 + $r(150) / 100));
        $expenditure = $this->money($base * 0.72 * (0.9 + $r(20) / 100));

        $builder = PayloadBuilder::for($institution->uuid, $period)
            ->status('final')
            ->sequence($sequence)
            ->generatedAt(Carbon::parse($period.'-01')->endOfMonth()->addDays(2)->toIso8601ZuluString())
            ->currency('XAF');

        $this->addLedger($builder, $scenario, $monthIndex, $income, $otherIncome, $expenditure);

        if (in_array(Capability::RECEIVABLES, $capabilities, true)) {
            $this->addReceivables($builder, $scenario, $monthIndex, $r);
        }

        if (in_array(Capability::PAYABLES, $capabilities, true)) {
            $builder->payables($this->money(1_200_000), [
                '0_30' => $this->money(800_000), '31_60' => $this->money(400_000),
                '61_90' => $this->money(0), '90_plus' => $this->money(0),
            ]);
        }

        if (in_array(Capability::BUDGET, $capabilities, true)) {
            $builder->budget($this->money($base * 0.8), $expenditure);
        }

        if (in_array(Capability::PAYROLL, $capabilities, true)) {
            $builder->payroll($this->money(3_100_000), $this->money(465_000));
        }

        if (in_array(Capability::ENROLMENT, $capabilities, true)) {
            $this->addEnrolment($builder, $scenario, $monthIndex, $income);
        }

        if (in_array(Capability::INTEGRITY, $capabilities, true)) {
            $builder->integrity(
                'sha256:'.hash('sha256', $institution->uuid.$period.$sequence),
                8 + $r(20),
                $scenario === 'restate_final' && $sequence > 1 ? 3 : $r(2),
                0
            );
        }

        return $builder->build();
    }

    /**
     * @param  callable(int): int  $r
     */
    private function addLedger(
        PayloadBuilder $builder,
        string $scenario,
        int $monthIndex,
        Money $income,
        Money $otherIncome,
        Money $expenditure
    ): void {
        $totalIncome = $income->add($otherIncome);
        $surplus = $totalIncome->subtract($expenditure);

        $debits = $this->money(12_480_000);

        // The one place figures are made deliberately wrong rather than merely
        // unflattering: the ledger stops balancing.
        $credits = $scenario === 'unbalanced' && $monthIndex >= 2
            ? $this->money(12_480_000 - 250_000 * $monthIndex)
            : $debits;

        $unposted = $scenario === 'unposted_backlog' ? 3 * ($monthIndex + 1) : 0;

        $builder->ledger(
            ['70' => $income, '71' => $otherIncome],
            ['60' => $expenditure->subtract($this->money(900_000)), '66' => $this->money(900_000)],
            $surplus,
            [
                'opening' => $this->money(2_300_000),
                'movement' => $surplus,
                'closing' => $this->money(2_300_000)->add($surplus),
            ],
            [
                'total_debits' => $debits,
                'total_credits' => $credits,
                'journal_entry_count' => 380 + $monthIndex * 7,
                'unposted_count' => $unposted,
            ]
        );
    }

    /**
     * @param  callable(int): int  $r
     */
    private function addReceivables(PayloadBuilder $builder, string $scenario, int $monthIndex, callable $r): void
    {
        // Cash that was received and never entered eventually has to be
        // explained as something. It usually gets explained as debt.
        $spike = $scenario === 'arrears_spike' ? 1 + $monthIndex * 0.9 : 1;

        $buckets = [
            '0_30' => $this->money(2_000_000 + $r(400_000)),
            '31_60' => $this->money(1_500_000),
            '61_90' => $this->money(900_000),
            '90_plus' => $this->money((int) round(1_000_000 * $spike)),
        ];

        $builder->receivables(Money::sum(array_values($buckets)), $buckets);
    }

    /**
     * Enrolment, expected fees and what was actually collected.
     *
     * The baseline here has to be genuinely healthy. An earlier version derived
     * expected fees from enrolment but collected from ledger income, and the two
     * had no reason to agree — so the "clean" scenario quietly tripped
     * collection_gap in four months out of six. A baseline that is not clean
     * makes every other scenario meaningless, because there is nothing to read
     * them against.
     *
     * So collection is modelled on its own terms: expected fees come from
     * enrolment times the fee, and collected is a share of that.
     */
    private function addEnrolment(PayloadBuilder $builder, string $scenario, int $monthIndex, Money $income): void
    {
        $feePerStudent = 10_000;
        $enrolment = 842;

        // A school that collects ~92% of billed fees in-month is doing well and
        // should raise nothing.
        $rate = 0.92;

        if ($scenario === 'ghost_students') {
            /*
             * Students arrive and the money does not. Enrolment climbs while
             * collection stays where it was, so the rate falls out of the
             * arithmetic rather than being set by hand — which is exactly how
             * this looks in a real school.
             */
            $enrolment += $monthIndex * 45;
        }

        if ($scenario === 'collection_gap') {
            $rate = max(0.15, 0.62 - $monthIndex * 0.05);
        }

        $expected = $this->money($enrolment * $feePerStudent);

        $collected = $scenario === 'ghost_students'
            ? $this->money(842 * $feePerStudent * 0.92)
            : $this->money((float) $expected->value() * $rate);

        $actualRate = (float) $expected->value() > 0
            ? min(1, round((float) $collected->value() / (float) $expected->value(), 4))
            : 0;

        $cashShare = $scenario === 'cash_creep'
            ? min(0.95, 0.31 + $monthIndex * 0.07)
            : 0.31;

        $builder->enrolment(
            $enrolment,
            $expected,
            $collected,
            number_format($actualRate, 2, '.', ''),
            number_format($cashShare, 2, '.', '')
        );
    }

    private function money(float|int $amount): Money
    {
        return Money::fromNumeric(round($amount, 2));
    }

    /**
     * A deterministic pseudo-random source.
     *
     * Deterministic so a scenario reproduces exactly: a findings regression test
     * that generated different figures each run would be a coin toss with extra
     * steps.
     *
     * @return callable(int): int
     */
    private function rng(int $institutionId, string $period): callable
    {
        $state = crc32($this->seed.'|'.$institutionId.'|'.$period);

        return function (int $max) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return $max > 0 ? $state % $max : 0;
        };
    }
}
