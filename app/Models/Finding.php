<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBody;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A control that fired (brief §11).
 *
 * "A control that only writes to a log is not a control." So a Finding has a
 * lifecycle, an owner and an explanation someone without an accounting
 * background can read — not a severity code and a stack of numbers.
 *
 * There is no `dismissed` status on purpose. A finding is answered, never made
 * to disappear: `explained` records that someone gave a reason, `accepted`
 * records that the body saw it and chose to live with it. Both keep the finding
 * and the name of whoever decided. Nothing is deleted where money is concerned.
 */
class Finding extends Model
{
    /**
     * Collapse findings into one entry per institution and control.
     *
     * A control that fires every month produces one finding per month, correctly,
     * since each month can be answered separately. Read as a flat list it buried
     * everything else: 24 near-identical cards pushed the money off the first
     * screen, and a limit of 8 hid the second school entirely.
     *
     * @param  iterable<int, self>  $findings
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function grouped(iterable $findings): \Illuminate\Support\Collection
    {
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        return collect($findings)
            ->groupBy(fn (self $f) => $f->institution_id.'|'.$f->rule)
            ->map(function ($group) use ($rank) {
                $ordered = $group->sortByDesc('period')->values();

                return (object) [
                    'latest' => $ordered->first(),
                    'findings' => $ordered,
                    'severity' => $ordered->sortBy(fn (self $f) => $rank[$f->severity] ?? 9)->first()->severity,
                ];
            })
            ->sortBy(fn ($g) => ($rank[$g->severity] ?? 9).'|'.$g->latest->institution?->name)
            ->values();
    }

    use Auditable;
    use BelongsToBody;

    public const OPEN = 'open';

    public const EXPLAINED = 'explained';

    public const ACCEPTED = 'accepted';

    /** Severity, most serious first. Used to order the console's findings list. */
    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    protected $fillable = [
        'body_id', 'institution_id', 'period', 'period_report_id',
        'rule', 'severity', 'title', 'explanation', 'figures', 'status',
        'resolved_by', 'resolved_at', 'resolution_note',
        'first_detected_at', 'last_seen_at', 'occurrences',
    ];

    protected function casts(): array
    {
        return [
            'figures' => 'array',
            'resolved_at' => 'datetime',
            'first_detected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function periodReport()
    {
        return $this->belongsTo(PeriodReport::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::OPEN]);
    }

    /**
     * Most serious first.
     *
     * The console orders findings by severity rather than by time because the
     * landing page is ordered by what needs attention, not by what is easy to
     * render (brief §10).
     */
    public function scopeBySeverity(Builder $query): Builder
    {
        /*
         * A CASE expression rather than MySQL's FIELD().
         *
         * FIELD() is MySQL-only: it does not exist in SQLite (which the test
         * suite runs on) or PostgreSQL, and the failure is a hard SQL error on
         * the console's most important page. CASE is standard SQL and says the
         * same thing.
         *
         * The severity list is a class constant rather than user input, so
         * interpolating it is safe; bindings are used anyway so the query plan
         * is cached and nobody has to re-verify that later.
         */
        $cases = [];
        $bindings = [];

        foreach (self::SEVERITIES as $rank => $severity) {
            $cases[] = 'WHEN ? THEN '.$rank;
            $bindings[] = $severity;
        }

        return $query
            ->orderByRaw('CASE severity '.implode(' ', $cases).' ELSE '.count(self::SEVERITIES).' END', $bindings)
            ->orderByDesc('last_seen_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * What a severity asks of the reader, in words.
     */
    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => __('Act now'),
            'high' => __('Needs action'),
            default => __('Worth a look'),
        };
    }

    /**
     * The figures behind this finding, with names a reader can follow and amounts
     * written as amounts. Internal fingerprints are left out: they mean nothing
     * to a person, and the versions on the school's page show the change.
     *
     * @return array<string, string>
     */
    public function figureLines(): array
    {
        $money = fn ($v) => money_text($v);
        $percent = fn ($v) => round((float) $v * ((float) $v <= 1 ? 100 : 1)).'%';
        $plain = fn ($v) => is_scalar($v) ? (string) $v : json_encode($v);

        $known = [
            'period' => [__('Month'), fn ($v) => period_text((string) $v)],
            'backdated_entries' => [__('Entries added after the month ended'), $plain],
            'journal_entry_count' => [__('Entries in the month'), $plain],
            'share_percent' => [__('Share of the month\'s entries'), fn ($v) => $v.'%'],
            'reversals' => [__('Entries reversed'), $plain],
            'previous_sequence' => [__('Earlier version'), $plain],
            'new_sequence' => [__('New version'), $plain],
            'previous_surplus' => [__('Income less spending, before'), $money],
            'new_surplus' => [__('Income less spending, after'), $money],
            'surplus_movement' => [__('Change'), $money],
            'days_between' => [__('Days between the two'), $plain],
            'cash_share' => [__('Taken as physical cash'), $percent],
            'threshold' => [__('Raised above'), $percent],
            'rise_from_earlier' => [__('Rise on earlier months'), $percent],
            'collected' => [__('Collected so far'), $money],
            'enrolment' => [__('Students'), $plain],
            'expected_fees' => [__('Fees billed so far'), $money],
            'shortfall' => [__('Difference'), $money],
            'collection_rate' => [__('Collected, as a share of fees billed'), $percent],
            'minimum_rate' => [__('Raised below'), $percent],
            'difference' => [__('Out of balance by'), $money],
            'days_silent' => [__('Days with nothing received'), $plain],
        ];

        $lines = [];

        foreach ((array) $this->figures as $key => $value) {
            if (str_ends_with((string) $key, '_hash') || $value === null) {
                continue;
            }

            [$label, $format] = $known[$key] ?? [ucfirst(str_replace('_', ' ', (string) $key)), $plain];
            $lines[$label] = $format($value);
        }

        return $lines;
    }

    public function severityRank(): int
    {
        $rank = array_search($this->severity, self::SEVERITIES, true);

        return $rank === false ? count(self::SEVERITIES) : $rank;
    }

    /**
     * Answer a finding.
     *
     * The note is required by the caller rather than optional here, because an
     * "accepted" with no reason attached is indistinguishable from someone
     * clearing their inbox.
     */
    public function resolve(string $status, User $user, string $note): void
    {
        $this->update([
            'status' => $status,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);

        AuditLog::log('finding.'.$status, static::class, $this->id, null, [
            'rule' => $this->rule,
            'institution_id' => $this->institution_id,
            'period' => $this->period,
            'note' => $note,
        ]);
    }
}
