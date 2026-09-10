<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Simulator\PushClient;
use App\Simulator\ScenarioGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pushes months of generated reports at the real ingest API.
 */
class SimulatePush extends Command
{
    protected $signature = 'edutrustpay:simulate:push
        {institution? : Institution slug or uuid. Omit to push for every active institution.}
        {--months=12 : How many calendar months, ending last month}
        {--scenario=clean : Which behaviour to simulate}
        {--profile= : Override the capability profile (full, ledger-only, posinnova-shaped)}
        {--url= : Push over real HTTP to this base URL instead of through the local kernel}
        {--seed= : Seed for reproducible figures}
        {--no-backdate : Leave receipt times as now, instead of pretending the reports arrived when they were dated}
        {--list-scenarios : Show the available scenarios and exit}';

    protected $description = 'Push simulated period reports through the ingest API, including fraud scenarios.';

    public function handle(): int
    {
        if ($this->option('list-scenarios')) {
            $this->table(
                ['Scenario', 'What it does'],
                collect(ScenarioGenerator::SCENARIOS)->map(fn ($d, $s) => [$s, $d])->values()->all()
            );

            return self::SUCCESS;
        }

        $scenario = (string) $this->option('scenario');

        if (! isset(ScenarioGenerator::SCENARIOS[$scenario])) {
            $this->error("Unknown scenario '$scenario'. Run with --list-scenarios to see them.");

            return self::FAILURE;
        }

        $institutions = $this->resolveInstitutions();

        if ($institutions->isEmpty()) {
            $this->error('No matching institution. Seed one first: php artisan db:seed --class=DemoBodySeeder');

            return self::FAILURE;
        }

        $generator = new ScenarioGenerator($this->option('seed') ? (int) $this->option('seed') : null);
        $months = max(1, (int) $this->option('months'));
        $periods = $this->periods($months);

        $this->info(sprintf(
            'Scenario "%s" — %d months (%s to %s) for %d institution(s), via %s.',
            $scenario,
            $months,
            $periods[0],
            end($periods),
            $institutions->count(),
            $this->option('url') ?: 'the local HTTP kernel'
        ));
        $this->line('  '.ScenarioGenerator::SCENARIOS[$scenario]);
        $this->newLine();

        $totals = ['accepted' => 0, 'duplicate' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($institutions as $institution) {
            $this->pushFor($institution, $generator, $periods, $scenario, $totals);
        }

        $this->newLine();
        $this->info(sprintf(
            'Done: %d accepted, %d duplicate, %d silent months, %d failed.',
            $totals['accepted'], $totals['duplicate'], $totals['skipped'], $totals['failed']
        ));

        if ($totals['accepted'] > 0) {
            $this->comment('Reports are queued. Run: php artisan queue:work --stop-when-empty');
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $periods
     * @param  array<string, int>  $totals
     */
    private function pushFor(
        Institution $institution,
        ScenarioGenerator $generator,
        array $periods,
        string $scenario,
        array &$totals
    ): void {
        $credential = $this->credentialFor($institution);

        if (! $credential) {
            $this->warn("  {$institution->name}: no usable credential, skipped.");

            return;
        }

        $client = new PushClient($credential['credential'], $credential['secret'], $this->option('url'));
        $capabilities = $this->capabilitiesFor($institution);

        $this->line("<info>{$institution->name}</info> <comment>[".implode(', ', $capabilities).']</comment>');

        foreach ($periods as $i => $period) {
            $payload = $generator->build($institution, $period, $i, count($periods), $scenario, $capabilities);

            if ($payload === null) {
                // Deliberate silence. A heartbeat here would defeat the point:
                // the institution has genuinely stopped talking.
                $totals['skipped']++;
                $this->line("  $period  <fg=gray>— silent</>");

                continue;
            }

            $result = $client->pushReport($payload);
            $this->report($period, $result, $totals);
        }

        if (! $this->option('no-backdate')) {
            $this->backdateReceipts($institution);
        }

        // A restatement lands after the fact, which is what makes it a control
        // event rather than a correction: the month had already been filed.
        if ($scenario === 'restate_final') {
            $target = $periods[max(0, count($periods) - 3)];
            $payload = $generator->build($institution, $target, 0, count($periods), 'restate_final', $capabilities, 2);

            $this->line("  <fg=yellow>restating $target at sequence 2</>");
            $this->report($target, $client->pushReport($payload), $totals);
        }
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     * @param  array<string, int>  $totals
     */
    private function report(string $period, array $result, array &$totals): void
    {
        $status = $result['status'];
        $body = $result['body'];

        if ($status === 202) {
            $totals['accepted']++;
            $warnings = $body['warnings'] ?? [];
            $this->line("  $period  <fg=green>accepted</>".($warnings ? '  <fg=yellow>'.implode(', ', $warnings).'</>' : ''));

            return;
        }

        if ($status === 200) {
            $totals['duplicate']++;
            $this->line("  $period  <fg=gray>duplicate</>");

            return;
        }

        $totals['failed']++;
        $this->line("  $period  <fg=red>HTTP $status</> ".($body['error'] ?? '').' '.implode('; ', $body['errors'] ?? []));
    }

    /**
     * Pretend the reports arrived when they were dated.
     *
     * The ingest API is right to stamp received_at as NOW — that is when it
     * actually received them. But a simulator that pushes twelve months in four
     * seconds leaves every institution looking like it reported this morning,
     * including the one whose whole purpose is to have stopped. The `silent`
     * scenario would then raise no finding, and the sweep would report nothing
     * wrong, which is precisely backwards.
     *
     * So after pushing, receipts are moved to two days after the month they
     * describe: the shape a real year of reporting would have left behind.
     * Pass --no-backdate to see the literal truth instead.
     */
    private function backdateReceipts(Institution $institution): void
    {
        $reports = $institution->periodReports()->get();

        foreach ($reports as $report) {
            $arrived = Carbon::parse($report->period.'-01')->endOfMonth()->addDays(2);

            $report->forceFill([
                'received_at' => $arrived,
                'processed_at' => $arrived,
            ])->saveQuietly();
        }

        $latest = $reports->max('period');

        if ($latest === null) {
            return;
        }

        $institution->forceFill([
            'last_report_at' => Carbon::parse($latest.'-01')->endOfMonth()->addDays(2),
            'last_period_received' => $latest,
        ])->saveQuietly();
    }

    /**
     * The last N complete calendar months, oldest first.
     *
     * @return array<int, string>
     */
    private function periods(int $months): array
    {
        $cursor = Carbon::now()->startOfMonth()->subMonths($months);

        return collect(range(1, $months))
            ->map(fn () => ($cursor = $cursor->addMonth())->format('Y-m'))
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Institution>
     */
    private function resolveInstitutions()
    {
        $query = Institution::withoutBodyScope()->with('capabilities')->where('is_active', true);

        if ($needle = $this->argument('institution')) {
            $query->where(fn ($q) => $q->where('slug', $needle)->orWhere('uuid', $needle));
        }

        return $query->get();
    }

    /**
     * @return array<int, string>
     */
    private function capabilitiesFor(Institution $institution): array
    {
        if ($profile = $this->option('profile')) {
            return ScenarioGenerator::PROFILES[$profile]
                ?? ScenarioGenerator::PROFILES['ledger-only'];
        }

        $declared = $institution->declaredCapabilities();

        // Fall back to the honest minimum rather than inventing capabilities the
        // institution never claimed.
        return $declared ?: ScenarioGenerator::PROFILES['ledger-only'];
    }

    /**
     * Get a usable credential, issuing one if this institution has none.
     *
     * Secrets are encrypted rather than hashed precisely so the platform can
     * recompute an HMAC; that is what makes this possible at all.
     *
     * @return array{credential: InstitutionCredential, secret: string}|null
     */
    private function credentialFor(Institution $institution): ?array
    {
        $credential = $institution->credentials()->active()->latest('id')->first();

        if ($credential) {
            return ['credential' => $credential, 'secret' => $credential->secret()];
        }

        return InstitutionCredential::issue($institution, 'Issued by simulator');
    }
}
