<?php

namespace App\Services;

use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\PayloadBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds genuine contract payloads from the live paxhitestbed ledger.
 *
 * READ ONLY. EdutrustPay is never the authority on an institution's books
 * (brief §4.1) — this exists to prove the contract against real accounting data
 * before a push client is written into that codebase, not to become a back door
 * into it. Every query here is a SELECT on a separate connection.
 *
 * WHAT THIS DECLARES, AND WHY IT IS LESS THAN YOU MIGHT EXPECT
 * ------------------------------------------------------------
 * paxhitestbed as a whole can report almost everything: its AgingReportService,
 * BudgetActualsService and TaxRemittanceService produce receivables ageing,
 * budget-vs-actual and statutory liabilities. But THIS extractor reads only the
 * general ledger, and ageing buckets cannot be derived from journal lines —
 * they need invoice-level dates that live in the source documents.
 *
 * So it declares `ledger` and `integrity` and nothing else. It would be trivial
 * to emit a receivables total from the class-4 balance and leave the buckets at
 * zero, and that is exactly the lie the whole platform exists to prevent. The
 * capability rule applies to us as much as to anyone else.
 *
 * OHADA classes, from the real chart: 1 capital, 2 fixed assets, 3 inventory,
 * 4 third parties, 5 treasury, 6 expenses, 7 revenue. Groups are the first two
 * digits of `account_code`, which in this system IS the OHADA code.
 */
class PaxhitestbedExtractor
{
    public const CLASS_TREASURY = 5;

    public const CLASS_EXPENSE = 6;

    public const CLASS_REVENUE = 7;

    public function __construct(private string $connection = 'paxhi_reference')
    {
    }

    private function db()
    {
        return DB::connection($this->connection);
    }

    public function isReachable(): bool
    {
        try {
            $this->db()->select('select 1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Every calendar month that has posted entries, oldest first.
     *
     * @return array<int, string>  "YYYY-MM"
     */
    public function availablePeriods(): array
    {
        return $this->db()->table('journal_entries')
            ->where('is_posted', 1)
            ->selectRaw("DATE_FORMAT(entry_date, '%Y-%m') as period")
            ->groupBy('period')
            ->orderBy('period')
            ->pluck('period')
            ->all();
    }

    /**
     * Build a signed-ready payload for one calendar month.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(string $institutionRef, string $period, string $status = 'final', int $sequence = 1): array
    {
        [$start, $end] = $this->bounds($period);

        $income = $this->groupTotals($start, $end, self::CLASS_REVENUE, 'credit');
        $expenditure = $this->groupTotals($start, $end, self::CLASS_EXPENSE, 'debit');

        $surplus = Money::sum(array_values($income))->subtract(Money::sum(array_values($expenditure)));

        return PayloadBuilder::for($institutionRef, $period)
            ->status($status)
            ->sequence($sequence)
            ->generatedAt(gmdate('Y-m-d\TH:i:s\Z'))
            ->currency('XAF')
            ->ledger($income, $expenditure, $surplus, $this->cash($start, $end), $this->control($start, $end))
            ->integrity(
                $this->ledgerHash($start, $end),
                $this->auditEvents($start, $end),
                $this->reversals($start, $end),
                $this->backdatedEntries($period, $start, $end)
            )
            ->build();
    }

    /**
     * Income or expenditure by OHADA group.
     *
     * Recomputed from journal_entry_lines every time. `chart_of_accounts.
     * current_balance` exists and would be far cheaper to read, but it is a
     * MUTABLE running total that post()/unpost() adjusts in place — a second
     * source of truth that edutrustshsm explicitly calls "cached/advisory only".
     * A consolidated position built on it would drift from the institution's own
     * printed statements with nothing to show why.
     *
     * Revenue accounts are credit-natured and expenses debit-natured, so each is
     * netted in its own direction; a refund posted against revenue reduces it
     * rather than appearing as an expense.
     *
     * @return array<string, Money>
     */
    private function groupTotals(string $start, string $end, int $class, string $normal): array
    {
        $rows = $this->db()->table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.is_posted', 1)
            ->whereNull('e.deleted_at')
            ->whereBetween('e.entry_date', [$start, $end])
            ->where('a.class_number', $class)
            ->selectRaw('LEFT(a.account_code, 2) as grp, SUM(l.debit) as dr, SUM(l.credit) as cr')
            ->groupBy('grp')
            ->orderBy('grp')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $net = $normal === 'credit'
                ? bcsub((string) $row->cr, (string) $row->dr, 2)
                : bcsub((string) $row->dr, (string) $row->cr, 2);

            $money = Money::fromNumeric($net);

            // A group that nets to nothing is noise, not information. Omitting it
            // is safe here because the capability IS declared: the console reads
            // an absent group inside a declared ledger as zero, which it is.
            if (! $money->isZero()) {
                $totals[(string) $row->grp] = $money;
            }
        }

        return $totals;
    }

    /**
     * Cash position from the class-5 treasury accounts.
     *
     * @return array{opening: Money, movement: Money, closing: Money}
     */
    private function cash(string $start, string $end): array
    {
        $opening = Money::fromNumeric($this->treasuryNet(null, Carbon::parse($start)->subDay()->toDateString()));
        $movement = Money::fromNumeric($this->treasuryNet($start, $end));

        return [
            'opening' => $opening,
            'movement' => $movement,
            // Computed, never read separately — so opening + movement = closing
            // always holds and the contract's cash_discontinuity warning can only
            // fire on a client that got it wrong, not on rounding.
            'closing' => $opening->add($movement),
        ];
    }

    private function treasuryNet(?string $start, string $end): string
    {
        $query = $this->db()->table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.is_posted', 1)
            ->whereNull('e.deleted_at')
            ->where('a.class_number', self::CLASS_TREASURY)
            ->where('e.entry_date', '<=', $end);

        if ($start !== null) {
            $query->where('e.entry_date', '>=', $start);
        }

        $row = $query->selectRaw('SUM(l.debit) as dr, SUM(l.credit) as cr')->first();

        return bcsub((string) ($row->dr ?? 0), (string) ($row->cr ?? 0), 2);
    }

    /**
     * Control totals, so the console can check the ledger balances itself rather
     * than trusting a summary.
     *
     * @return array{total_debits: Money, total_credits: Money, journal_entry_count: int, unposted_count: int}
     */
    private function control(string $start, string $end): array
    {
        $totals = $this->db()->table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.is_posted', 1)
            ->whereNull('e.deleted_at')
            ->whereBetween('e.entry_date', [$start, $end])
            ->selectRaw('SUM(l.debit) as dr, SUM(l.credit) as cr')
            ->first();

        return [
            'total_debits' => Money::fromNumeric((string) ($totals->dr ?? 0)),
            'total_credits' => Money::fromNumeric((string) ($totals->cr ?? 0)),
            'journal_entry_count' => $this->entryCount($start, $end, true),

            /*
             * Unposted entries are a real signal in THIS system specifically:
             * paxhitestbed's unpost() mutates account balances backwards instead
             * of posting a contra entry, so figures can be removed from the
             * ledger without leaving a reversing trail.
             */
            'unposted_count' => $this->entryCount($start, $end, false),
        ];
    }

    private function entryCount(string $start, string $end, bool $posted): int
    {
        return (int) $this->db()->table('journal_entries')
            ->where('is_posted', $posted ? 1 : 0)
            ->whereNull('deleted_at')
            ->whereBetween('entry_date', [$start, $end])
            ->count();
    }

    /**
     * A hash over the period's posted journal lines.
     *
     * This is what lets the console detect that a period's underlying detail
     * changed even when its totals did not — the shape fraud model B takes when
     * someone reclassifies rather than re-values. Ordered explicitly so the hash
     * is reproducible regardless of how the database chooses to return rows.
     */
    private function ledgerHash(string $start, string $end): string
    {
        $rows = $this->db()->table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.is_posted', 1)
            ->whereNull('e.deleted_at')
            ->whereBetween('e.entry_date', [$start, $end])
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('l.line_number')
            ->orderBy('l.id')
            ->select('e.entry_date', 'e.entry_number', 'a.account_code', 'l.debit', 'l.credit')
            ->get();

        $material = $rows->map(fn ($r) => implode('|', [
            $r->entry_date, $r->entry_number, $r->account_code,
            number_format((float) $r->debit, 2, '.', ''),
            number_format((float) $r->credit, 2, '.', ''),
        ]))->implode("\n");

        return 'sha256:'.hash('sha256', $material);
    }

    private function reversals(string $start, string $end): int
    {
        return (int) $this->db()->table('journal_entries')
            ->whereNull('deleted_at')
            ->whereBetween('entry_date', [$start, $end])
            ->where(function ($q) {
                $q->where('is_reversed', 1)->orWhere('reference_type', 'like', '%reversal%');
            })
            ->count();
    }

    /**
     * Entries added to a month AFTER that month had ended.
     *
     * THE NAIVE DEFINITION IS USELESS, and this is worth recording because it is
     * not obvious until you look at real data. "entry_date < DATE(created_at)"
     * flags 784 of the 794 entries in this ledger — because 780 of them were
     * bulk-created on 2026-08-18 carrying historical dates. Any real bursary
     * behaves the same way in miniature: last month's transactions are entered
     * this month, every month, entirely legitimately.
     *
     * A control that fires on almost every entry is not a control; it trains
     * everyone to ignore it. So the question asked here is narrower and actually
     * means something: how much of this month's reported figure was entered
     * after the month closed? Combined with restatement detection — a period
     * that changes after it was reported — that is the pair of signals fraud
     * model B actually produces.
     *
     * Reversals are excluded regardless, because
     * TransactionAutoMapService::reversalPlacement() deliberately dates a
     * reversal to the original entry when its period is still open.
     */
    private function backdatedEntries(string $period, string $start, string $end): int
    {
        $monthClosed = Carbon::parse($end)->endOfDay();

        return (int) $this->db()->table('journal_entries')
            ->whereNull('deleted_at')
            ->whereBetween('entry_date', [$start, $end])
            ->where('created_at', '>', $monthClosed)
            ->where('is_reversed', 0)
            ->where(function ($q) {
                $q->whereNull('reference_type')->orWhere('reference_type', 'not like', '%reversal%');
            })
            ->count();
    }

    /**
     * Audit-log entries touching accounting models in the window.
     *
     * Note what this cannot tell us: paxhitestbed's audit_logs table has no hash
     * chain and no signature, so it is append-only by convention only. The count
     * is a weak signal on its own — the ledger_hash above is the one that has
     * teeth.
     */
    private function auditEvents(string $start, string $end): int
    {
        try {
            return (int) $this->db()->table('audit_logs')
                ->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59'])
                ->where(function ($q) {
                    $q->where('auditable_type', 'like', '%JournalEntry%')
                        ->orWhere('auditable_type', 'like', '%ChartOfAccount%')
                        ->orWhere('auditable_type', 'like', '%AccountingPeriod%');
                })
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function bounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
