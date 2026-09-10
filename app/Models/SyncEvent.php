<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every interaction with an institution, including the ones carrying no figures.
 *
 * The heartbeat matters as much as the report (brief §8). Without it there is no
 * way to tell a quiet month from a stopped one, and "silence is an alarm" only
 * works if silence is distinguishable from "nothing to say".
 */
class SyncEvent extends Model
{
    public const RECEIVED = 'received';

    public const REJECTED = 'rejected';

    public const RESTATED = 'restated';

    public const HEARTBEAT = 'heartbeat';

    public const STALE = 'stale';

    public const RECOVERED = 'recovered';

    public $timestamps = false;

    protected $fillable = [
        'institution_id', 'type', 'period', 'period_report_id',
        'summary', 'detail', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return ['detail' => 'array', 'created_at' => 'datetime'];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function periodReport()
    {
        return $this->belongsTo(PeriodReport::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    public static function record(
        int $institutionId,
        string $type,
        ?string $period = null,
        ?string $summary = null,
        ?array $detail = null,
        ?int $periodReportId = null
    ): self {
        return static::create([
            'institution_id' => $institutionId,
            'type' => $type,
            'period' => $period,
            'period_report_id' => $periodReportId,
            'summary' => $summary,
            'detail' => $detail,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
