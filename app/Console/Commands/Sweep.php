<?php

namespace App\Console\Commands;

use App\Models\Finding;
use App\Models\Institution;
use App\Services\FindingEngine;
use Illuminate\Console\Command;

/**
 * The staleness sweep.
 *
 * Everything else in the platform is triggered by something arriving. This is
 * the only thing triggered by something NOT arriving, which makes it the one
 * scheduled job that must never quietly stop running: if it does, the console
 * keeps showing the last known position and every institution looks fine.
 *
 * Run daily. Idempotent — a finding that is still true has its occurrence count
 * advanced rather than being raised again.
 */
class Sweep extends Command
{
    protected $signature = 'edutrustpay:sweep {--dry-run : Report what would fire without writing findings}';

    protected $description = 'Check for institutions that have gone silent and raise findings.';

    public function handle(FindingEngine $engine): int
    {
        $active = Institution::withoutBodyScope()->where('is_active', true)->count();

        if ($this->option('dry-run')) {
            return $this->dryRun($active);
        }

        $before = Finding::withoutBodyScope()->where('rule', 'not_reporting')->count();

        $raised = $engine->sweep();

        $new = Finding::withoutBodyScope()->where('rule', 'not_reporting')->count() - $before;

        $this->info(sprintf(
            'Swept %d active institution(s): %d silent, %d newly raised.',
            $active,
            $raised->count(),
            max(0, $new)
        ));

        if ($raised->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Institution', 'Severity', 'Finding', 'Seen'],
                $raised->map(fn (Finding $f) => [
                    $f->institution->name,
                    $f->severity,
                    $f->title,
                    $f->occurrences,
                ])->all()
            );
        }

        $recovered = Finding::withoutBodyScope()
            ->where('rule', 'not_reporting')
            ->where('status', Finding::EXPLAINED)
            ->whereNull('resolved_by')
            ->whereDate('resolved_at', today())
            ->count();

        if ($recovered > 0) {
            $this->line(sprintf('%d institution(s) resumed reporting and were closed automatically.', $recovered));
        }

        return self::SUCCESS;
    }

    /**
     * Show what would fire without writing anything.
     *
     * Worth having because the thresholds are a governance decision: somebody
     * will want to see what "35 days" actually catches before agreeing to it.
     */
    private function dryRun(int $active): int
    {
        $silent = Institution::withoutBodyScope()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Institution $i) => $i->isSilent());

        $this->info(sprintf('%d of %d active institution(s) are past their threshold.', $silent->count(), $active));

        if ($silent->isNotEmpty()) {
            $this->table(
                ['Institution', 'Days silent', 'Threshold', 'Last period'],
                $silent->map(fn (Institution $i) => [
                    $i->name,
                    $i->daysSilent() ?? 'never reported',
                    $i->notReportingDays(),
                    $i->last_period_received ?? '—',
                ])->all()
            );
        }

        $this->comment('Nothing was written.');

        return self::SUCCESS;
    }
}
