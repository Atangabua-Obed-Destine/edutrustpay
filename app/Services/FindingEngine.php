<?php

namespace App\Services;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Models\SyncEvent;
use Illuminate\Support\Collection;

/**
 * Runs the controls and turns what they say into Findings.
 *
 * Rules stay pure — a report in, a draft out — and every decision about
 * persistence lives here, so there is one place to reason about the question
 * that actually matters:
 *
 *   WHEN IS SOMETHING A NEW FINDING, AND WHEN IS IT THE SAME ONE STILL TRUE?
 *
 * Getting that wrong in either direction ruins the feature. Raise a fresh
 * finding every night and the console fills with duplicates until nobody reads
 * it. Never raise a second one and a condition that recurs months later hides
 * behind a note somebody wrote in March.
 *
 * The answer here is (institution, period, rule): the same control firing about
 * the same month is one finding, and its occurrence count and last_seen_at
 * advance. The same control about a DIFFERENT month is a new finding, because it
 * is a new fact about a different set of figures.
 */
class FindingEngine
{
    /**
     * Rules that run when a report arrives.
     *
     * @var array<int, class-string<Rule>>
     */
    private const REPORT_RULES = [
        \App\Findings\Rules\FinalRestatedRule::class,
        \App\Findings\Rules\UnbalancedLedgerRule::class,
        \App\Findings\Rules\BackdatedEntriesRule::class,
        \App\Findings\Rules\UnpostedBacklogRule::class,
        \App\Findings\Rules\CollectionGapRule::class,
        \App\Findings\Rules\CashShareHighRule::class,
    ];

    /**
     * Rules that run on a schedule, about what has NOT arrived.
     *
     * @var array<int, class-string<Rule>>
     */
    private const SWEEP_RULES = [
        \App\Findings\Rules\NotReportingRule::class,
    ];

    /**
     * Evaluate every report-stage rule against a newly processed report.
     *
     * @return Collection<int, Finding>
     */
    public function evaluateReport(PeriodReport $report): Collection
    {
        $institution = $report->institution
            ?? Institution::withoutBodyScope()->find($report->institution_id);

        if (! $institution) {
            return collect();
        }

        $history = PeriodReport::withoutBodyScope()
            ->where('institution_id', $institution->id)
            ->where('is_current', true)
            ->where('period', '<', $report->period)
            ->orderByDesc('period')
            ->limit(6)
            ->get();

        return $this->run(self::REPORT_RULES, $institution, $report, $history);
    }

    /**
     * Evaluate the time-based rules across every active institution.
     *
     * @return Collection<int, Finding>
     */
    public function sweep(): Collection
    {
        $found = collect();

        Institution::withoutBodyScope()
            ->with('body')
            ->where('is_active', true)
            ->chunkById(100, function (Collection $institutions) use ($found) {
                foreach ($institutions as $institution) {
                    $found->push(...$this->run(self::SWEEP_RULES, $institution, null, collect()));

                    $this->clearRecoveredSilence($institution);
                }
            });

        return $found;
    }

    /**
     * @param  array<int, class-string<Rule>>  $rules
     * @param  Collection<int, PeriodReport>  $history
     * @return Collection<int, Finding>
     */
    private function run(array $rules, Institution $institution, ?PeriodReport $report, Collection $history): Collection
    {
        $raised = collect();

        foreach ($rules as $class) {
            /** @var Rule $rule */
            $rule = new $class();
            $config = (array) config('findings.rules.'.$rule->code(), []);

            // A rule can be switched off entirely. Thresholds are a governance
            // decision and will be argued about; a bursary head who cannot
            // change one without a deployment stops trusting the finding.
            if (($config['enabled'] ?? true) === false) {
                continue;
            }

            $draft = $rule->evaluate(new RuleContext($institution, $report, $history, $config));

            if ($draft === null) {
                continue;
            }

            $raised->push($this->record($institution, $draft, $report, $config));
        }

        return $raised;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function record(Institution $institution, FindingDraft $draft, ?PeriodReport $report, array $config): Finding
    {
        /*
         * Matched with an explicit whereNull for a period-less rule.
         *
         * MySQL treats NULLs as distinct in a unique index, so the database
         * constraint does NOT stop duplicate `not_reporting` rows — this is the
         * code that has to. Without it the nightly sweep would create a new
         * finding every night for every silent institution.
         */
        $existing = Finding::withoutBodyScope()
            ->where('institution_id', $institution->id)
            ->where('rule', $draft->rule)
            ->when($draft->period === null,
                fn ($q) => $q->whereNull('period'),
                fn ($q) => $q->where('period', $draft->period)
            )
            ->first();

        $severity = $config['severity'] ?? $draft->severity;

        if ($existing) {
            /*
             * The condition is still true.
             *
             * A finding somebody has already answered is NOT reopened here. That
             * would overwrite a human decision with a machine's opinion every
             * night, which is the fastest way to teach people to ignore the
             * console. last_seen_at still advances, so the console can show that
             * an answered condition has not gone away.
             */
            $existing->forceFill([
                'last_seen_at' => now(),
                'occurrences' => $existing->occurrences + 1,
                'severity' => $existing->isOpen() ? $severity : $existing->severity,
                'explanation' => $existing->isOpen() ? $draft->explanation : $existing->explanation,
                'figures' => $existing->isOpen() ? $draft->figures : $existing->figures,
            ])->saveQuietly();

            return $existing;
        }

        return Finding::withoutBodyScope()->create([
            'body_id' => $institution->body_id,
            'institution_id' => $institution->id,
            'period' => $draft->period,
            'period_report_id' => $report?->id,
            'rule' => $draft->rule,
            'severity' => $severity,
            'title' => $draft->title,
            // Stored, not generated at render time: a restated period rewrites
            // the figures underneath, and a finding that silently rewords itself
            // afterwards is worthless in an audit.
            'explanation' => $draft->explanation,
            'figures' => $draft->figures,
            'status' => Finding::OPEN,
            'first_detected_at' => now(),
            'last_seen_at' => now(),
            'occurrences' => 1,
        ]);
    }

    /**
     * An institution that has started reporting again.
     *
     * Answering this one automatically is defensible where it would not be for
     * any other rule: the condition was "we have heard nothing", and hearing
     * something answers it completely. Leaving it open would mean the console
     * accumulates findings about problems that fixed themselves, which is how a
     * findings list becomes wallpaper.
     *
     * The episode is NOT erased — the finding stays with its dates, its
     * occurrence count and an explicit note saying reporting resumed, and a
     * SyncEvent records the recovery. Nothing is deleted where money is
     * concerned; it is answered, and the answer says who answered it.
     */
    private function clearRecoveredSilence(Institution $institution): void
    {
        if ($institution->isSilent()) {
            return;
        }

        $open = Finding::withoutBodyScope()
            ->where('institution_id', $institution->id)
            ->where('rule', 'not_reporting')
            ->whereNull('period')
            ->where('status', Finding::OPEN)
            ->first();

        if (! $open) {
            return;
        }

        $silentDays = $open->first_detected_at->diffInDays($open->last_seen_at);

        $open->forceFill([
            'status' => Finding::EXPLAINED,
            'resolved_at' => now(),
            // Deliberately null: no person decided this, and attributing it to
            // one would be a small lie in the audit trail.
            'resolved_by' => null,
            'resolution_note' => __(
                'Reporting resumed automatically on :date. The institution was silent from :from to :to '
                .'(:days days); the last period received is :period. Closed by the platform, not by a person.',
                [
                    'date' => now()->toFormattedDateString(),
                    'from' => $open->first_detected_at->toFormattedDateString(),
                    'to' => $open->last_seen_at->toFormattedDateString(),
                    'days' => $silentDays,
                    'period' => $institution->last_period_received ?? __('none'),
                ]
            ),
        ])->saveQuietly();

        SyncEvent::record(
            $institution->id,
            SyncEvent::RECOVERED,
            $institution->last_period_received,
            __('Reporting resumed after :days days of silence.', ['days' => $silentDays])
        );
    }
}
