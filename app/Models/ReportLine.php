<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One OHADA group's amount within a period report.
 *
 * Consolidation happens here and nowhere else. Comparing a college against a
 * clinic is only possible because both arrive as OHADA classes, and neither had
 * to adopt the other's chart of accounts to do it.
 *
 * Not Auditable: these rows are derived from an immutable signed payload and are
 * written once by the ingest job. Auditing them would record the queue's own
 * work, not a person's.
 */
class ReportLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['period_report_id', 'section', 'ohada_group', 'amount'];

    protected function casts(): array
    {
        // A decimal string on every driver — see PeriodReport for why this is
        // pinned rather than left to PDO.
        return ['amount' => 'decimal:2'];
    }

    public function periodReport()
    {
        return $this->belongsTo(PeriodReport::class);
    }

    /**
     * The OHADA class (1-9) this group belongs to — the first digit.
     */
    public function classNumber(): int
    {
        return (int) substr($this->ohada_group, 0, 1);
    }
}
