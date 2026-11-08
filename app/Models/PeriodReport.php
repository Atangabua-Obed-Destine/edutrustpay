<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBody;
use App\Support\Figure;
use Carbon\CarbonInterface;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One institution's figures for one calendar month, as received.
 *
 * The raw signed payload is kept alongside the exploded columns so any figure
 * can be traced back to the exact bytes that were signed. The columns exist for
 * speed; the payload is the evidence.
 *
 * Restatement (brief §4.7): a higher sequence for a period supersedes the one
 * before it. Superseding NEVER overwrites — the earlier row keeps its figures
 * and its signature, is_current flips to false, and both stay visible. If a
 * FINAL period is restated that is a control event, not routine housekeeping:
 * it is what fraud model B looks like from the outside.
 */
class PeriodReport extends Model
{
    use Auditable;
    use BelongsToBody;

    protected $fillable = [
        'institution_id', 'body_id', 'period', 'status', 'sequence',
        'supersedes_id', 'superseded_by_id', 'is_current',
        'contract_version', 'currency', 'capabilities', 'group_names',
        'payload', 'payload_hash', 'signature', 'key_id',
        'generated_at', 'received_at', 'processed_at', 'warnings',
        'surplus_deficit', 'cash_opening', 'cash_movement', 'cash_closing',
        'total_debits', 'total_credits', 'journal_entry_count', 'unposted_count',
        'receivables_total', 'receivables_0_30', 'receivables_31_60', 'receivables_61_90', 'receivables_90_plus',
        'payables_total', 'payables_0_30', 'payables_31_60', 'payables_61_90', 'payables_90_plus',
        'budgeted', 'budget_actual',
        'staff_cost', 'statutory_liabilities',
        'bank_statement_closing', 'bank_ledger_closing', 'bank_divergence', 'bank_statement_ref',
        'enrolment', 'expected_fees', 'collected', 'collection_rate', 'cash_share',
        'ledger_hash', 'audit_events', 'reversals', 'backdated_entries',
    ];

    /**
     * The raw payload is excluded from audit records: it is large, and it is
     * already stored verbatim on this row. Duplicating it into the audit trail
     * on every change would bloat the table without adding evidence.
     */
    protected $hidden = ['payload'];

    /**
     * Money columns that must always read back as decimal STRINGS.
     *
     * @var array<int, string>
     */
    private const MONEY_COLUMNS = [
        'surplus_deficit', 'cash_opening', 'cash_movement', 'cash_closing',
        'total_debits', 'total_credits',
        'receivables_total', 'receivables_0_30', 'receivables_31_60', 'receivables_61_90', 'receivables_90_plus',
        'payables_total', 'payables_0_30', 'payables_31_60', 'payables_61_90', 'payables_90_plus',
        'budgeted', 'budget_actual',
        'staff_cost', 'statutory_liabilities',
        'bank_statement_closing', 'bank_ledger_closing', 'bank_divergence',
        'expected_fees', 'collected',
    ];

    protected function casts(): array
    {
        $casts = [
            'capabilities' => 'array',
            'group_names' => 'array',
            'warnings' => 'array',
            'is_current' => 'boolean',
            'sequence' => 'integer',
            'generated_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];

        /*
         * Cast every money column to decimal:2, which returns a STRING.
         *
         * This is not cosmetic. PDO drivers disagree about DECIMAL: MySQL hands
         * back "1040000.00" while SQLite hands back the number 1040000, so
         * without this the same column is a string in production and a float in
         * the test suite — and a float is exactly what Money refuses to be built
         * from, for reasons that end in a broken signature or a drifting total.
         * Pinning the cast makes the type a property of the model rather than of
         * whichever database happens to be underneath it.
         */
        foreach (self::MONEY_COLUMNS as $column) {
            $casts[$column] = 'decimal:2';
        }

        // Rates carry 4 decimal places, matching the contract's rate pattern.
        $casts['collection_rate'] = 'decimal:4';
        $casts['cash_share'] = 'decimal:4';

        return $casts;
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function lines()
    {
        return $this->hasMany(ReportLine::class);
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy()
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function findings()
    {
        return $this->hasMany(Finding::class);
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    /**
     * Reports covering a fiscal year that starts in a given month.
     *
     * The console assembles fiscal years from calendar months; institutions
     * never report one. paxhitestbed runs January-December and the schools run
     * July-June, so a single body holds both and only the console can reconcile
     * them (brief §7.1).
     *
     * @return array<int, string> the twelve periods, in order
     */
    public static function fiscalYearPeriods(int $startYear, int $startMonth): array
    {
        $periods = [];
        $cursor = \Carbon\Carbon::create($startYear, $startMonth, 1);

        for ($i = 0; $i < 12; $i++) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->copy()->addMonth();
        }

        return $periods;
    }

    // ---------------------------------------------------------------
    // Capabilities and figures
    // ---------------------------------------------------------------

    /**
     * Did this report actually carry a capability?
     */
    public function declares(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    /**
     * Turn a stored column into a Figure, with the reason attached when there
     * is no number.
     *
     * This is the single place where a nullable database column becomes a
     * rendered figure, so it is the single place the missing-vs-zero decision is
     * made. Note that the check is on the CAPABILITY, not on the column being
     * null: a genuinely-zero receivables total is 0.00 with the capability
     * declared, and must render as 0 — refusing to distinguish those would be
     * the same failure in the opposite direction.
     */
    public function figure(string $column, string $capability, ?CarbonInterface $staleAfter = null): Figure
    {
        if (! $this->declares($capability)) {
            return Figure::notCapable(__(':institution does not report :capability yet.', [
                'institution' => $this->institution?->name ?? __('This institution'),
                'capability' => $capability,
            ]));
        }

        $raw = $this->getAttribute($column);

        if ($raw === null) {
            // The capability was declared but this figure is absent — a
            // malformed report would have been refused at ingest, so this means
            // the block genuinely carried no such value.
            return Figure::notReported();
        }

        $money = Money::of((string) $raw);
        $asOf = $this->generated_at;

        if ($staleAfter && $asOf && $asOf->lessThan($staleAfter)) {
            return Figure::stale($money, $asOf);
        }

        return Figure::reported($money, $asOf);
    }

    /**
     * Income or expenditure by OHADA group, as Money keyed by group.
     *
     * @return array<string, Money>
     */
    public function groupTotals(string $section): array
    {
        return $this->lines
            ->where('section', $section)
            ->mapWithKeys(fn (ReportLine $line) => [$line->ohada_group => Money::of((string) $line->amount)])
            ->all();
    }

    // ---------------------------------------------------------------
    // Evidence
    // ---------------------------------------------------------------

    /**
     * The payload as it was signed, decoded.
     *
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        return Canonical::decode($this->payload);
    }

    /**
     * Does the stored payload still hash to what we recorded on receipt?
     *
     * EdutrustPay is not the authority on an institution's books, but it IS the
     * authority on what it was sent — so it must be able to prove that what it
     * is showing today is what arrived.
     */
    public function payloadIsIntact(): bool
    {
        return hash('sha256', $this->payload) === $this->payload_hash;
    }

    public function isRestatement(): bool
    {
        return $this->sequence > 1;
    }

    public function isFinal(): bool
    {
        return $this->status === 'final';
    }

    /**
     * @return array<int, array{code: string, message: string, context: array<string, mixed>}>
     */
    public function warningList(): array
    {
        return $this->warnings ?? [];
    }
}
