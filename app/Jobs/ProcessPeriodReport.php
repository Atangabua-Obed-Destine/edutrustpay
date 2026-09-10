<?php

namespace App\Jobs;

use App\Models\PeriodReport;
use App\Models\SyncEvent;
use App\Services\FindingEngine;
use App\Services\ReportIngestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Explodes a received report into its figures, lines and findings.
 *
 * Ingest accepts fast and processes here (brief §8), because the institution may
 * be on a phone hotspot with seconds of connectivity: making it wait while we
 * unpack a payload and evaluate a dozen rules is how a push fails halfway and
 * gets retried forever.
 *
 * The report row itself is written synchronously before this job is dispatched,
 * so the database's idempotency constraint has already done its work by the time
 * we get here. This job is therefore safe to retry: everything it does is
 * idempotent by construction.
 */
class ProcessPeriodReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $periodReportId)
    {
    }

    public function handle(ReportIngestService $ingest, FindingEngine $findings): void
    {
        $report = PeriodReport::withoutBodyScope()->with('institution')->find($this->periodReportId);

        if (! $report) {
            // The institution or body was deleted between accept and process.
            // Nothing to do, and nothing worth failing the queue over.
            return;
        }

        $ingest->process($report);

        /*
         * Controls run AFTER the figures have been exploded, not before: several
         * of them read the columns the ingest service has just written, and one
         * (final_restated) depends on the supersession chain it resolves.
         */
        $findings->evaluateReport($report->refresh());
    }

    /**
     * A report that cannot be processed must not vanish silently.
     *
     * The payload is already stored and signed, so nothing is lost — but an
     * institution whose reports are arriving and failing to process would
     * otherwise look, from the console, exactly like one reporting normally.
     * That is the worst of both worlds, so the failure is recorded where the
     * console can see it.
     */
    public function failed(\Throwable $e): void
    {
        $report = PeriodReport::withoutBodyScope()->find($this->periodReportId);

        if (! $report) {
            return;
        }

        SyncEvent::record(
            $report->institution_id,
            SyncEvent::REJECTED,
            $report->period,
            'Report was accepted but could not be processed.',
            ['period_report_id' => $report->id, 'error' => $e->getMessage()],
            $report->id
        );

        Log::error('Failed to process period report', [
            'period_report_id' => $this->periodReportId,
            'exception' => $e->getMessage(),
        ]);
    }
}
