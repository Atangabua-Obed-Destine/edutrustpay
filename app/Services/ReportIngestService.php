<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\InstitutionCapability;
use App\Models\PeriodReport;
use App\Models\ReportLine;
use App\Models\SyncEvent;
use EdutrustPay\Contract\Capability;
use Illuminate\Support\Facades\DB;

/**
 * Turns a received payload into rows.
 *
 * Kept out of the controller and the job so the same logic serves both the HTTP
 * path and the simulator, and so restatement — the part with real consequences —
 * lives in one readable place.
 *
 * Shape follows edutrustshsm's service conventions: a plain autowired class, no
 * interface, no binding, array shapes documented rather than DTO classes.
 */
class ReportIngestService
{
    /**
     * Explode a stored report into its figure columns and OHADA lines, resolve
     * the restatement chain, and update the institution's liveness.
     *
     * Idempotent: running it twice on the same report produces the same rows.
     *
     * @return array{restated: bool, superseded_id: int|null}
     */
    public function process(PeriodReport $report): array
    {
        $payload = $report->decodedPayload();

        return DB::transaction(function () use ($report, $payload) {
            $this->applyFigures($report, $payload);
            $this->applyLines($report, $payload);

            $result = $this->resolveSupersession($report);

            $this->observeCapabilities($report, $payload);
            $this->updateLiveness($report);

            $report->forceFill(['processed_at' => now()])->saveQuietly();

            return $result;
        });
    }

    /**
     * Copy the payload's figures into their columns.
     *
     * A block that is absent leaves its columns NULL, and that is the whole
     * point — NULL means "not reported", 0.00 means "reported and genuinely
     * zero". Nothing here has a fallback value, deliberately: a `?? 0` anywhere
     * in this method would quietly convert a reporting gap into a number.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyFigures(PeriodReport $report, array $payload): void
    {
        $financial = $payload['financial'] ?? [];
        $operational = $payload['operational'] ?? [];
        $integrity = $payload['integrity'] ?? [];

        $values = [];

        if (isset($financial['surplus_deficit'])) {
            $values['surplus_deficit'] = $financial['surplus_deficit'];
        }

        foreach (['opening' => 'cash_opening', 'movement' => 'cash_movement', 'closing' => 'cash_closing'] as $key => $column) {
            if (isset($financial['cash'][$key])) {
                $values[$column] = $financial['cash'][$key];
            }
        }

        foreach (['total_debits', 'total_credits', 'journal_entry_count', 'unposted_count'] as $key) {
            if (isset($financial['control'][$key])) {
                $values[$key] = $financial['control'][$key];
            }
        }

        foreach (['receivables', 'payables'] as $block) {
            if (! isset($financial[$block])) {
                continue;
            }

            $values[$block.'_total'] = $financial[$block]['total'];

            foreach (['0_30', '31_60', '61_90', '90_plus'] as $bucket) {
                $values[$block.'_'.$bucket] = $financial[$block]['ageing'][$bucket];
            }
        }

        if (isset($financial['budget'])) {
            $values['budgeted'] = $financial['budget']['budgeted'];
            $values['budget_actual'] = $financial['budget']['actual'];
        }

        if (isset($financial['payroll'])) {
            $values['staff_cost'] = $financial['payroll']['staff_cost'];
            $values['statutory_liabilities'] = $financial['payroll']['statutory_liabilities'];
        }

        if (isset($financial['bank_recon'])) {
            $values['bank_statement_closing'] = $financial['bank_recon']['statement_closing'];
            $values['bank_ledger_closing'] = $financial['bank_recon']['ledger_closing'];
            $values['bank_divergence'] = $financial['bank_recon']['divergence'];
            $values['bank_statement_ref'] = $financial['bank_recon']['statement_ref'];
        }

        foreach (['enrolment', 'expected_fees', 'collected', 'collection_rate', 'cash_share'] as $key) {
            if (isset($operational[$key])) {
                $values[$key] = $operational[$key];
            }
        }

        foreach (['ledger_hash', 'audit_events', 'reversals', 'backdated_entries'] as $key) {
            if (isset($integrity[$key])) {
                $values[$key] = $integrity[$key];
            }
        }

        $report->forceFill($values)->saveQuietly();
    }

    /**
     * Write the OHADA group lines.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyLines(PeriodReport $report, array $payload): void
    {
        // Re-processing must not double the lines.
        $report->lines()->delete();

        $rows = [];

        foreach (['income' => 'income_by_group', 'expenditure' => 'expenditure_by_group'] as $section => $key) {
            $groups = $payload['financial'][$key] ?? null;

            if ($groups === null) {
                continue;
            }

            foreach ((array) $groups as $group => $amount) {
                $rows[] = [
                    'period_report_id' => $report->id,
                    'section' => $section,
                    'ohada_group' => (string) $group,
                    'amount' => $amount,
                ];
            }
        }

        if ($rows !== []) {
            ReportLine::insert($rows);
        }
    }

    /**
     * Resolve which report currently stands for this period.
     *
     * Supersession NEVER overwrites (brief §4.7): the earlier row keeps its
     * figures and its signature, is_current flips to false, and both stay
     * visible in the console. Restating a FINAL period is a control event, and
     * the SyncEvent written here is what the findings engine reads.
     *
     * Out-of-order delivery is expected — an institution's outbox may retry an
     * old month after a newer one has arrived — so the report that stands is the
     * one with the HIGHEST sequence, not the one that arrived last.
     *
     * @return array{restated: bool, superseded_id: int|null}
     */
    private function resolveSupersession(PeriodReport $report): array
    {
        $siblings = PeriodReport::withoutBodyScope()
            ->where('institution_id', $report->institution_id)
            ->where('period', $report->period)
            ->orderByDesc('sequence')
            ->get();

        $winner = $siblings->first();

        /*
         * Stand the losers down BEFORE promoting the winner.
         *
         * The database now enforces at most one current row per period, so the
         * order of these two writes is not cosmetic: promoting first would put
         * two rows in the current state for the width of one statement and the
         * unique index would reject the whole delivery. The previous code did
         * exactly that and passed only because nothing was checking.
         *
         * Standing down first is also the safer of the two orders if a write
         * fails in between: a period with NO current row renders as "not
         * reported", which is true-ish and visible. A period with two renders
         * as whichever the driver returned last, which is invisible and wrong.
         */
        foreach ($siblings as $sibling) {
            if ($sibling->id !== $winner->id && $sibling->is_current) {
                $sibling->forceFill(['is_current' => false])->saveQuietly();
            }
        }

        if (! $winner->is_current) {
            $winner->forceFill(['is_current' => true])->saveQuietly();
        }

        // The in-memory copy the caller holds is one of these rows; keep it
        // honest rather than making the caller re-read.
        if ($report->id === $winner->id) {
            $report->is_current = true;
        }

        $previous = $siblings->firstWhere('sequence', '<', $report->sequence);

        if ($previous === null || $winner->id !== $report->id) {
            return ['restated' => false, 'superseded_id' => null];
        }

        $report->forceFill(['supersedes_id' => $previous->id])->saveQuietly();
        $previous->forceFill(['superseded_by_id' => $report->id])->saveQuietly();

        SyncEvent::record(
            $report->institution_id,
            SyncEvent::RESTATED,
            $report->period,
            sprintf(
                'Period %s restated at sequence %d, superseding sequence %d (previously %s).',
                $report->period,
                $report->sequence,
                $previous->sequence,
                $previous->status
            ),
            [
                'superseded_report_id' => $previous->id,
                'superseded_sequence' => $previous->sequence,
                // The findings engine reads this: restating a period that was
                // already FINAL is fraud model B, not routine bookkeeping.
                'superseded_status' => $previous->status,
                'new_status' => $report->status,
            ],
            $report->id
        );

        return ['restated' => true, 'superseded_id' => $previous->id];
    }

    /**
     * Record which capabilities have actually been seen arriving.
     *
     * The gap between declared and observed is worth surfacing: a capability an
     * operator recorded but which never appears means the institution is not
     * sending what it promised, which is quieter than not reporting at all.
     *
     * @param  array<string, mixed>  $payload
     */
    private function observeCapabilities(PeriodReport $report, array $payload): void
    {
        foreach ($payload['capabilities'] ?? [] as $capability) {
            if (! Capability::isValid($capability)) {
                continue;
            }

            $row = InstitutionCapability::firstOrNew([
                'institution_id' => $report->institution_id,
                'capability' => $capability,
            ]);

            $row->first_seen_at ??= now();
            $row->last_seen_at = now();
            $row->source ??= 'observed';
            $row->save();
        }
    }

    /**
     * Keep the denormalised liveness columns current.
     *
     * "Which institutions have not reported, and for how long" is the most
     * valuable widget on the platform, so it must not require scanning
     * period_reports on every page load.
     *
     * Guarded against going backwards: a late-arriving old month must not make
     * an institution look more recently seen than it is.
     */
    private function updateLiveness(PeriodReport $report): void
    {
        $institution = $report->institution ?? Institution::withoutBodyScope()->find($report->institution_id);

        if (! $institution) {
            return;
        }

        $values = [];

        if ($institution->last_report_at === null || $institution->last_report_at->lessThan($report->received_at)) {
            $values['last_report_at'] = $report->received_at;
        }

        if ($institution->last_period_received === null || $report->period > $institution->last_period_received) {
            $values['last_period_received'] = $report->period;
        }

        if ($values !== []) {
            $institution->forceFill($values)->saveQuietly();
        }
    }
}
