<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\InstitutionCapability;
use App\Models\InstitutionCredential;
use App\Services\PaxhitestbedExtractor;
use App\Simulator\PushClient;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Money;
use Illuminate\Console\Command;

/**
 * Emits genuine contract payloads from the live paxhitestbed ledger and pushes
 * them through the real ingest API.
 *
 * This is the cheapest possible proof that the contract survives contact with
 * real accounting data. It reads paxhitestbed READ ONLY on a separate
 * connection, so nothing is written into that system and no client code has to
 * be added to it yet — while still exercising the OHADA grouping, the balancing
 * control totals, the ledger hash and the signature end to end.
 *
 * What it finds is as useful as what it proves. Run against the testbed it
 * reports large `backdated_entries` counts for historical months, which is
 * correct: 780 of that ledger's 794 entries were bulk-created on one day in
 * August 2026 carrying dates going back to October 2025. The extractor is
 * telling the truth about its source.
 */
class SimulateFromPaxhitestbed extends Command
{
    protected $signature = 'edutrustpay:simulate:from-paxhitestbed
        {institution? : Institution slug or uuid to file the reports under}
        {--months= : Only the most recent N months}
        {--url= : Push over real HTTP to this base URL instead of the local kernel}
        {--dry-run : Build and print the payloads without pushing them}';

    protected $description = 'Build real contract payloads from the live paxhitestbed ledger (read only) and push them.';

    public function handle(PaxhitestbedExtractor $extractor): int
    {
        if (! $extractor->isReachable()) {
            $this->error('Cannot reach the paxhitestbed reference database.');
            $this->line('Check the PAXHI_DB_* settings in .env. This connection is read only by design.');

            return self::FAILURE;
        }

        $periods = $extractor->availablePeriods();

        if ($periods === []) {
            $this->error('The reference ledger has no posted journal entries.');

            return self::FAILURE;
        }

        if ($months = (int) $this->option('months')) {
            $periods = array_slice($periods, -$months);
        }

        $institution = $this->resolveInstitution();

        if (! $institution) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Extracting %d month(s) — %s to %s — from the live paxhitestbed ledger, read only.',
            count($periods),
            $periods[0],
            end($periods)
        ));
        $this->line("Filing under: {$institution->name}");
        $this->newLine();

        $client = null;

        if (! $this->option('dry-run')) {
            $credential = $this->credentialFor($institution);
            $client = new PushClient($credential['credential'], $credential['secret'], $this->option('url'));
            $this->declareCapabilities($institution);
        }

        $rows = [];
        $failed = 0;

        foreach ($periods as $period) {
            try {
                $payload = $extractor->buildPayload($institution->uuid, $period);
            } catch (\Throwable $e) {
                $this->error("  $period  could not be built: ".$e->getMessage());
                $failed++;

                continue;
            }

            // The builder's payload still holds Money objects; they only become
            // decimal strings when Canonical serialises them. Sum them as Money
            // rather than casting to float — casting here would reintroduce
            // exactly the imprecision Money exists to keep out.
            $financial = $payload['financial'];
            $income = Money::sum(array_values((array) $financial['income_by_group']));
            $expenditure = Money::sum(array_values((array) $financial['expenditure_by_group']));

            $status = '—';

            if ($client) {
                $result = $client->pushReport($payload);
                $status = match ($result['status']) {
                    202 => '<fg=green>accepted</>',
                    200 => '<fg=gray>duplicate</>',
                    default => '<fg=red>'.$result['status'].' '.($result['body']['error'] ?? '').'</>',
                };

                if (! in_array($result['status'], [200, 202], true)) {
                    $failed++;
                }
            }

            $rows[] = [
                $period,
                $this->amount($income),
                $this->amount($expenditure),
                $this->amount($financial['surplus_deficit']),
                $financial['control']['journal_entry_count'],
                // The interesting column: how much of this month was entered
                // after the month had ended.
                $payload['integrity']['backdated_entries'],
                $status,
            ];
        }

        $this->table(
            ['Period', 'Income', 'Expenditure', 'Surplus', 'Entries', 'Late', 'Push'],
            $rows
        );

        $this->newLine();
        $this->line('Declared capabilities: <comment>ledger, integrity</comment>');
        $this->line(
            'Not declared: receivables, payables, budget, payroll, enrolment, bank_recon — the general '
            .'ledger alone cannot produce ageing buckets or fee expectations, and reporting a total with '
            .'zeroed buckets would be exactly the lie this platform exists to prevent.'
        );

        if ($client) {
            $this->comment('Run: php artisan queue:work --stop-when-empty');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function amount(Money $money): string
    {
        return number_format((float) $money->value(), 0, '.', ' ');
    }

    private function resolveInstitution(): ?Institution
    {
        $query = Institution::withoutBodyScope();

        if ($needle = $this->argument('institution')) {
            $institution = $query->where('slug', $needle)->orWhere('uuid', $needle)->first();
        } else {
            $institution = $query->where('system', 'paxhitestbed')->first();
        }

        if (! $institution) {
            $this->error('No matching institution. Seed one: php artisan db:seed --class=DemoBodySeeder');

            return null;
        }

        return $institution;
    }

    /**
     * Record what this source can actually report, so the console renders the
     * rest as "not yet reporting" rather than as zero.
     */
    private function declareCapabilities(Institution $institution): void
    {
        foreach ([Capability::LEDGER, Capability::INTEGRITY] as $capability) {
            InstitutionCapability::updateOrCreate(
                ['institution_id' => $institution->id, 'capability' => $capability],
                ['source' => 'declared', 'declared_at' => now()]
            );
        }
    }

    /**
     * @return array{credential: InstitutionCredential, secret: string}
     */
    private function credentialFor(Institution $institution): array
    {
        $credential = $institution->credentials()->active()->latest('id')->first();

        return $credential
            ? ['credential' => $credential, 'secret' => $credential->secret()]
            : InstitutionCredential::issue($institution, 'Issued by paxhitestbed extractor');
    }
}
