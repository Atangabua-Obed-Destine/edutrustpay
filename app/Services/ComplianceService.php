<?php

namespace App\Services;

use App\Models\ComplianceCase;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Models\Regulation;
use App\Models\RegulationAttestation;
use App\Models\RegulationMapping;
use App\Models\RegulationWaiver;
use Carbon\Carbon;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Money;
use Illuminate\Support\Collection;

/**
 * Compares what schools report with the rules their body set.
 *
 * A school can never look compliant by saying nothing. Silence is "cannot be
 * checked", the same reasoning that keeps a missing figure from reading as zero.
 * And a fee the schedule does not recognise is not assumed to be fine or wrong:
 * it waits for a person to say which line it counts as.
 *
 * This sees what is recorded in a school's system. A levy collected off the books
 * looks compliant here, so a clean board is not proof of a clean school.
 */
class ComplianceService
{
    public const COMPLIES = 'complies';

    public const BREACH = 'breach';

    public const UNKNOWN = 'unknown';

    public const WAIVED = 'waived';

    public const NOT_LIVE = 'not_live';

    /** Fee figures older than this many months say little about today. */
    private const STALE_AFTER_MONTHS = 3;

    /**
     * Every regulation in force over these institutions, against each of them.
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array{regulations: Collection, rows: array<int, object>, totals: array<string, int>}
     */
    public function board(Collection $institutions): array
    {
        $institutions->loadMissing('body');

        $bodyIds = $institutions->flatMap(fn (Institution $i) => $i->body?->pathIds() ?? [])->unique()->all();

        $regulations = Regulation::withoutBodyScope()
            ->with('feeItems')
            ->whereIn('body_id', $bodyIds)
            ->where('is_active', true)
            ->orderBy('area')->orderBy('title')
            ->get()
            ->filter(fn (Regulation $r) => $r->isInForce())
            ->values();

        $totals = [self::COMPLIES => 0, self::BREACH => 0, self::UNKNOWN => 0, self::WAIVED => 0, self::NOT_LIVE => 0];
        $rows = [];

        foreach ($institutions as $institution) {
            $cells = [];

            foreach ($regulations as $regulation) {
                if (! $regulation->coversInstitution($institution)) {
                    $cells[$regulation->id] = null;

                    continue;
                }

                $cells[$regulation->id] = $this->check($regulation, $institution);
                $totals[$cells[$regulation->id]->state]++;
            }

            $rows[] = (object) ['institution' => $institution, 'cells' => $cells];
        }

        return ['regulations' => $regulations, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * One rule against one institution.
     */
    public function check(Regulation $regulation, Institution $institution): object
    {
        $waiver = RegulationWaiver::query()->active()
            ->where('regulation_id', $regulation->id)
            ->where('institution_id', $institution->id)
            ->with('grantor')->latest('id')->first();

        $result = $regulation->check_type === Regulation::FEE_SCHEDULE
            ? $this->checkFees($regulation, $institution)
            : $this->checkAttestation($regulation, $institution);

        // What the rule found stays visible underneath, so a waiver never hides it.
        $result->found = $result->state;
        $result->waiver = $waiver;

        if ($institution->live_from?->isFuture()) {
            $result->state = self::NOT_LIVE;
            $result->summary = __('Being migrated. Judged from :date.', ['date' => $institution->live_from->toFormattedDateString()]);
        } elseif ($waiver) {
            $result->state = self::WAIVED;
            $result->summary = __('Waived until :date: :reason', [
                'date' => $waiver->expires_on->toFormattedDateString(),
                'reason' => $waiver->reason,
            ]);
        }

        return $result;
    }

    private function checkFees(Regulation $regulation, Institution $institution): object
    {
        $report = $this->latestFeeReport($institution);

        if (! $report) {
            return $this->result(self::UNKNOWN, __('No fee figures have been received from this school.'));
        }

        if ($report->period < $regulation->effective_from->format('Y-m')) {
            return $this->result(self::UNKNOWN, __('The latest fee figures are for :period, before this rule began.', ['period' => period_text($report->period)]), report: $report);
        }

        if (Carbon::createFromFormat('Y-m', $report->period)->startOfMonth()->lt(today()->startOfMonth()->subMonths(self::STALE_AFTER_MONTHS))) {
            return $this->result(self::UNKNOWN, __('The latest fee figures are for :period, too old to rely on.', ['period' => period_text($report->period)]), report: $report);
        }

        $block = $report->decodedPayload()['operational']['fees_charged'] ?? null;
        $lines = $this->compare($regulation, $institution, (array) ($block['rows'] ?? []));

        $over = $lines->where('state', 'over')->count();
        $under = $lines->where('state', 'under')->count();
        $unmatched = $lines->where('state', 'unmatched')->count();
        $checked = $lines->whereIn('state', ['ok', 'over', 'under'])->count();

        $state = match (true) {
            $over + $under > 0 => self::BREACH,
            $unmatched > 0 || $checked === 0 => self::UNKNOWN,
            default => self::COMPLIES,
        };

        $parts = [];

        if ($over > 0) {
            $parts[] = trans_choice('{1}1 fee is above the schedule|[2,*]:count fees are above the schedule', $over, ['count' => $over]);
        }

        if ($under > 0) {
            $parts[] = trans_choice('{1}1 fee is below the schedule|[2,*]:count fees are below the schedule', $under, ['count' => $under]);
        }

        if ($unmatched > 0) {
            $parts[] = trans_choice('{1}1 fee is not matched to the schedule yet|[2,*]:count fees are not matched to the schedule yet', $unmatched, ['count' => $unmatched]);
        }

        if ($parts === []) {
            $parts[] = $checked > 0
                ? trans_choice('{1}1 fee checked, within the schedule|[2,*]:count fees checked, all within the schedule', $checked, ['count' => $checked])
                : __('No fee here falls under this rule.');
        }

        return $this->result($state, __(':summary. Figures for :period.', ['summary' => implode('; ', $parts), 'period' => period_text($report->period)]), $lines, $report);
    }

    private function checkAttestation(Regulation $regulation, Institution $institution): object
    {
        $latest = RegulationAttestation::query()
            ->where('regulation_id', $regulation->id)
            ->where('institution_id', $institution->id)
            ->with('attester')->latest('id')->first();

        if (! $latest) {
            return $this->result(self::UNKNOWN, __('Nobody has confirmed this yet.'));
        }

        if ($latest->valid_until->lt(today())) {
            return $this->result(self::UNKNOWN, __('The last confirmation ran out on :date.', ['date' => $latest->valid_until->toFormattedDateString()]), attestation: $latest);
        }

        $name = $latest->attester?->name ?? __('someone');

        return $this->result(
            $latest->outcome === self::COMPLIES ? self::COMPLIES : self::BREACH,
            $latest->outcome === self::COMPLIES
                ? __('Confirmed by :name, not checked by the system.', ['name' => $name])
                : __('Reported as not followed by :name.', ['name' => $name]),
            attestation: $latest
        );
    }

    /**
     * Each fee the school charges, against the schedule.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, object>
     */
    private function compare(Regulation $regulation, Institution $institution, array $rows): Collection
    {
        $items = $regulation->feeItems;
        $byName = $items->keyBy(fn ($item) => $this->key($item->level, $item->category));

        $mappings = RegulationMapping::query()
            ->where('regulation_id', $regulation->id)
            ->where('institution_id', $institution->id)
            ->get()
            ->keyBy(fn (RegulationMapping $m) => $this->key($m->school_level, $m->school_category));

        // Two amounts for one class and fee mean people in a class pay differently.
        $amountsPerFee = collect($rows)->groupBy(fn ($r) => $this->key($r['level'], $r['category']))->map->count();

        return collect($rows)->map(function (array $row) use ($regulation, $items, $byName, $mappings, $amountsPerFee) {
            $key = $this->key($row['level'], $row['category']);
            $mapping = $mappings->get($key);
            $amount = Money::of((string) $row['amount']);

            $item = $mapping?->regulation_fee_item_id
                ? $items->firstWhere('id', $mapping->regulation_fee_item_id)
                : ($mapping?->ignored ? null : $byName->get($key));

            $state = 'unmatched';
            $allowed = null;
            $difference = null;

            if ($mapping?->ignored) {
                $state = 'ignored';
            } elseif ($item) {
                $allowed = Money::of((string) $item->amount);
                $difference = $amount->subtract($allowed);
                $compare = $amount->compare($allowed);

                $state = match (true) {
                    $compare > 0 => 'over',
                    $compare < 0 && $regulation->fee_mode === 'exact' => 'under',
                    default => 'ok',
                };
            }

            return (object) [
                'level' => (string) $row['level'],
                'category' => (string) $row['category'],
                'amount' => $amount->value(),
                'students' => (int) ($row['students'] ?? 0),
                'allowed' => $allowed?->value(),
                'difference' => $difference?->value(),
                'state' => $state,
                'varies' => ($amountsPerFee[$key] ?? 1) > 1,
                'item_id' => $mapping?->regulation_fee_item_id,
                'mapped_by_hand' => $mapping !== null,
            ];
        })->values();
    }

    /**
     * The newest report carrying fee figures. Older months keep theirs for the
     * record, so only the latest says what is being charged now.
     */
    public function latestFeeReport(Institution $institution): ?PeriodReport
    {
        return PeriodReport::withoutBodyScope()
            ->where('institution_id', $institution->id)
            ->where('is_current', true)
            ->orderByDesc('period')
            ->limit(6)
            ->get()
            ->first(fn (PeriodReport $r) => $r->declares(Capability::FEES_CHARGED));
    }

    /**
     * @param  Collection<int, Institution>  $institutions
     * @return Collection<int, ComplianceCase>
     */
    public function openCases(Collection $institutions): Collection
    {
        return ComplianceCase::query()->open()
            ->whereIn('institution_id', $institutions->pluck('id'))
            ->with('regulation', 'institution', 'assignee')
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')
            ->get();
    }

    private function key(string $level, string $category): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $level))).'|'.mb_strtolower(trim(preg_replace('/\s+/', ' ', $category)));
    }

    private function result(string $state, string $summary, ?Collection $lines = null, ?PeriodReport $report = null, ?RegulationAttestation $attestation = null): object
    {
        return (object) [
            'state' => $state,
            'summary' => $summary,
            'lines' => $lines ?? collect(),
            'report' => $report,
            'attestation' => $attestation,
        ];
    }
}
