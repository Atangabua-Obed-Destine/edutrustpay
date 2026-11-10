<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBody;
use EdutrustPay\Contract\Capability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An institution — a school, hospital, pharmacy or shop owned by a body.
 *
 * EdutrustPay holds identity and reporting history for an institution. It does
 * NOT hold its books: the institution keeps those, and this platform receives
 * summaries (brief §4.1). Nothing here should ever grow a row about an
 * identifiable student or patient. Staff are the one exception, approved so a
 * body can check postings person by person: a report carries each member of
 * staff's number, name, department and post, never pay, and only people with
 * staff.view see the list.
 */
class Institution extends Model
{
    use Auditable;
    use BelongsToBody;

    protected $fillable = [
        'body_id', 'uuid', 'name', 'slug', 'type', 'system', 'endpoint',
        'connectivity_mode', 'contract_version', 'city', 'contact_name',
        'contact_email', 'contact_phone', 'not_reporting_days', 'fiscal_year_start_month', 'live_from',
        'last_report_at', 'last_heartbeat_at', 'last_period_received', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_report_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'not_reporting_days' => 'integer',
            'fiscal_year_start_month' => 'integer',
            'live_from' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Institution $institution) {
            $institution->uuid ??= (string) Str::uuid();
            $institution->slug = $institution->slug ?: Str::slug($institution->name);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** An institution identifies itself by its own primary key. */
    public function getInstitutionColumn(): ?string
    {
        return 'id';
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function credentials()
    {
        return $this->hasMany(InstitutionCredential::class);
    }

    public function activeCredential()
    {
        return $this->hasOne(InstitutionCredential::class)->whereNull('revoked_at')->latestOfMany();
    }

    public function capabilities()
    {
        return $this->hasMany(InstitutionCapability::class);
    }

    public function periodReports()
    {
        return $this->hasMany(PeriodReport::class);
    }

    public function currentReports()
    {
        return $this->hasMany(PeriodReport::class)->where('is_current', true);
    }

    public function syncEvents()
    {
        return $this->hasMany(SyncEvent::class);
    }

    public function findings()
    {
        return $this->hasMany(Finding::class);
    }

    public function accountMappings()
    {
        return $this->hasMany(AccountMapping::class);
    }

    // ---------------------------------------------------------------
    // Capabilities
    // ---------------------------------------------------------------

    /**
     * Capabilities this institution is expected to report.
     *
     * @return array<int, string>
     */
    public function declaredCapabilities(): array
    {
        return $this->capabilities->pluck('capability')->all();
    }

    public function declares(string $capability): bool
    {
        return in_array($capability, $this->declaredCapabilities(), true);
    }

    /**
     * Capabilities that could ever apply here.
     *
     * A business has no enrolment, and the console must render that as "not
     * applicable" rather than "not yet reporting" — the difference between a gap
     * that will close and one that never will. Showing a pharmacy as
     * permanently behind on student counts would train people to ignore the
     * capability matrix, which is the one place the group's real reporting
     * coverage is visible.
     *
     * @return array<int, string>
     */
    public function applicableCapabilities(): array
    {
        return Capability::applicableTo($this->type);
    }

    /**
     * How long this institution may be silent before `not_reporting` fires.
     *
     * Institution overrides body, which overrides the platform default. A rural
     * mission that reaches a hotspot once a month and a city hospital on fibre
     * should not share a threshold, or one of the two numbers is wrong.
     */
    public function notReportingDays(): int
    {
        return $this->not_reporting_days
            ?? $this->body?->not_reporting_days
            ?? (int) config('findings.rules.not_reporting.days', 35);
    }

    /**
     * The month this institution's own financial year opens.
     *
     * Institution overrides body, which falls back to January. Same cascade as
     * notReportingDays() above, for the same reason: most institutions match
     * their body and should not have to restate it, but the ones that differ
     * differ for good reasons — a school's year follows its terms, and the
     * Archdiocese that owns it keeps calendar books.
     *
     * This changes no reported figure. Institutions always report calendar
     * months; this only decides which twelve the console adds up, and which
     * month a year-to-date column opens from. Getting it wrong produces a
     * statement that reconciles to nothing the institution itself publishes —
     * which is harder to notice than a wrong number, and worse.
     */
    public function fiscalYearStartMonth(): int
    {
        // With no setting of its own, a school's year is the year its budget
        // runs over, so "year so far" and its budget sheet add up the same months.
        return $this->fiscal_year_start_month
            ?? $this->budgetYearStartMonth()
            ?? $this->body?->fiscal_year_start_month
            ?? 1;
    }

    /** The month its latest budget sheet's year opens, or null with no sheet. */
    public function budgetYearStartMonth(): ?int
    {
        $starts = BudgetSheet::withoutGlobalScopes()
            ->where('institution_id', $this->id)
            ->where('is_current', true)
            ->orderByDesc('period')
            ->value('budget_starts');

        return $starts ? (int) \Illuminate\Support\Carbon::parse($starts)->month : null;
    }

    /**
     * Days since we last heard ANYTHING — a report or a heartbeat.
     *
     * Null means we have never heard from this institution at all, which is a
     * different problem from having stopped hearing, and the console says so.
     */
    public function daysSilent(): ?int
    {
        $lastSeen = collect([$this->last_report_at, $this->last_heartbeat_at])->filter()->max();

        return $lastSeen ? (int) $lastSeen->diffInDays(now()) : null;
    }

    public function isSilent(): bool
    {
        $days = $this->daysSilent();

        // Never heard from, and enrolled long enough ago that we should have.
        if ($days === null) {
            return $this->created_at !== null
                && $this->created_at->diffInDays(now()) > $this->notReportingDays();
        }

        return $days > $this->notReportingDays();
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfSystem(Builder $query, string $system): Builder
    {
        return $query->where('system', $system);
    }

    /**
     * The report that currently stands for a period — the highest sequence
     * received, with any superseded versions retained but not current.
     */
    /** @var array<string, string>|null */
    protected ?array $classNamesCache = null;

    /**
     * The school's own name for each account group, as its latest reports give
     * them ("71" => "Scolarites").
     *
     * Each report carries the school's whole list, and the latest name wins, so
     * a month sent before the school sent names still reads with them; it is the
     * same chart of accounts. A group the school has never named has no entry,
     * and is shown by its number alone.
     *
     * @return array<string, string>
     */
    public function accountClassNames(): array
    {
        if ($this->classNamesCache !== null) {
            return $this->classNamesCache;
        }

        $names = [];

        $lists = $this->periodReports()
            ->where('is_current', true)
            ->whereNotNull('group_names')
            ->orderBy('period')
            ->orderBy('sequence')
            ->pluck('group_names');

        foreach ($lists as $list) {
            foreach ((array) $list as $group => $name) {
                $names[(string) $group] = (string) $name;
            }
        }

        return $this->classNamesCache = $names;
    }

    public function reportFor(string $period): ?PeriodReport
    {
        return $this->periodReports()
            ->where('period', $period)
            ->where('is_current', true)
            ->first();
    }
}
