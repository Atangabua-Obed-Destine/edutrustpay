<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Body;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\PeriodReport;
use App\Models\ReportLine;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove the worked example, keep the platform.
 *
 * The demo bodies exist so the console can be clicked through before a single
 * institution is connected. That is worth having and it is also a hazard: the
 * figures are invented, they look exactly like real ones, and a consolidated
 * total that mixes a demonstration into live reporting is the specific failure
 * this platform exists to prevent.
 *
 * So this is a hard boundary rather than a tidy-up. It takes out the demo
 * bodies and EVERYTHING beneath them, and it refuses to run at all if anything
 * real has arrived in the meantime — because by then "demo" and "live" are no
 * longer separable by a slug, and nobody should find that out from a deletion.
 */
class ClearDemoData extends Command
{
    protected $signature = 'edutrustpay:clear-demo
        {--force : Skip the confirmation}
        {--discard-connected : Delete even institutions a real system has signed into, and their reports}';

    protected $description = 'Delete the demonstration bodies and their data, leaving roles, permissions and the operator account.';

    /**
     * The slugs DemoBodySeeder creates. Matching on slug rather than on "seeded
     * before date X" keeps this exact: a body somebody created by hand is never
     * a candidate, whenever they created it.
     */
    private const DEMO_SLUGS = [
        'archdiocese-of-bamenda',
        'bamenda-central-deanery',
        'kumbo-deanery',
        'diocese-of-buea',
    ];

    private const DEMO_EMAILS = [
        'bursar@bamenda.test',
        'auditor@bamenda.test',
        'deanery@bamenda.test',
        'bursar@buea.test',
    ];

    public function handle(): int
    {
        $bodies = Body::query()->whereIn('slug', self::DEMO_SLUGS)->get();

        if ($bodies->isEmpty()) {
            $this->info('No demonstration bodies found. Nothing to do.');

            return self::SUCCESS;
        }

        $bodyIds = $bodies->pluck('id')->all();

        // Anything real hiding inside a demo body. Reports carry live figures
        // from a real bursary; deleting them because the body they were filed
        // against happens to be called "Archdiocese of Bamenda" would destroy
        // the one thing here that cannot be regenerated.
        $strays = User::whereIn('body_id', $bodyIds)
            ->whereNotIn('email', self::DEMO_EMAILS)
            ->pluck('email');

        if ($strays->isNotEmpty()) {
            $this->error('Real accounts exist inside the demonstration bodies:');
            $this->line('  '.$strays->implode(', '));
            $this->newLine();
            $this->line('These bodies are being used for real work. Move or delete those accounts by hand first,');
            $this->line('or rename the bodies — this command will not guess which data is which.');

            return self::FAILURE;
        }

        $institutionIds = Institution::withoutBodyScope()->whereIn('body_id', $bodyIds)->pluck('id')->all();
        $reportIds = PeriodReport::withoutBodyScope()->whereIn('institution_id', $institutionIds)->pluck('id')->all();

        /*
         * A REAL system pushing into a demo body.
         *
         * The demo bodies are named after plausible institutions, and the
         * fastest way to try the platform is to point a real school at one of
         * those names rather than create a body first. That is exactly what
         * happened here: "Catholic University of Bamenda" is a seeded name with
         * PAX HIGHER INSTITUTE's live ledger behind it — 794 journal entries,
         * signed and delivered by its own client.
         *
         * A credential with `last_used_at` set is the proof. It means something
         * out there holds this secret and has successfully signed with it, which
         * no amount of seeding can produce. Those reports cannot be regenerated:
         * they are the institution's own history, and the signatures over them
         * are the only evidence of what it actually filed.
         *
         * Checking only for real user ACCOUNTS, as this first did, misses this
         * entirely — a connected school has no user here at all.
         */
        $connected = InstitutionCredential::query()
            ->whereIn('institution_id', $institutionIds)
            ->whereNotNull('last_used_at')
            ->with('institution')
            ->get();

        if ($connected->isNotEmpty() && ! $this->option('discard-connected')) {
            $this->error('Real systems are reporting into these demonstration bodies:');

            foreach ($connected as $credential) {
                $this->line(sprintf(
                    '  %-34s %s  (last signed %s, %d reports)',
                    $credential->institution?->name,
                    $credential->key_id,
                    $credential->last_used_at?->diffForHumans(),
                    PeriodReport::withoutBodyScope()->where('institution_id', $credential->institution_id)->count()
                ));
            }

            $this->newLine();
            $this->line('Those reports are that institution\'s own history and cannot be regenerated.');
            $this->line('Move the institution to a real body first (Bodies, then edit the institution),');
            $this->line('or revoke its credential if the connection was only a trial.');
            $this->line('Pass --discard-connected only if you genuinely mean to delete them.');

            return self::FAILURE;
        }

        /*
         * The override is deliberately loud rather than silent.
         *
         * --discard-connected exists because starting from a clean platform is
         * a legitimate thing to want, and refusing forever would just push
         * somebody towards deleting rows by hand, which is worse. But the flag
         * must not make the consequence easier to overlook than the guard did:
         * whoever runs it should read what they are destroying before the
         * confirmation, not discover it afterwards.
         */
        if ($connected->isNotEmpty()) {
            $this->warn('--discard-connected: deleting institutions that real systems have signed into.');

            foreach ($connected as $credential) {
                $this->line(sprintf(
                    '  %-34s %d reports will be destroyed',
                    $credential->institution?->name,
                    PeriodReport::withoutBodyScope()->where('institution_id', $credential->institution_id)->count()
                ));
            }

            $this->line('Those systems will start failing to report until they are re-enrolled and given new credentials.');
            $this->newLine();
        }

        $this->table(['What', 'Count'], [
            ['Bodies', count($bodyIds)],
            ['Institutions', count($institutionIds)],
            ['Period reports', count($reportIds)],
            ['Findings', Finding::withoutBodyScope()->whereIn('institution_id', $institutionIds)->count()],
            ['Demo users', User::whereIn('email', self::DEMO_EMAILS)->count()],
        ]);

        if (! $this->option('force') && ! $this->confirm('Delete all of this permanently?')) {
            $this->comment('Nothing was deleted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($bodyIds, $institutionIds, $reportIds) {
            ReportLine::whereIn('period_report_id', $reportIds)->delete();
            SyncEvent::whereIn('institution_id', $institutionIds)->delete();
            Finding::withoutBodyScope()->whereIn('institution_id', $institutionIds)->delete();
            PeriodReport::withoutBodyScope()->whereIn('id', $reportIds)->delete();

            Institution::withoutBodyScope()->whereIn('id', $institutionIds)->delete();

            User::whereIn('email', self::DEMO_EMAILS)->delete();

            // The audit trail goes too, and only here. It describes acts
            // performed against data that no longer exists, on a body that was
            // never real — keeping it would leave a record of a history the
            // platform is now asserting did not happen.
            AuditLog::withoutGlobalScopes()->whereIn('body_id', $bodyIds)->delete();

            // Deepest first, so a parent is never removed while a child still
            // points at it.
            Body::whereIn('id', $bodyIds)->orderByDesc('depth')->get()->each->delete();
        });

        $this->newLine();
        $this->info('Demonstration data removed. Roles, permissions and the operator account are untouched.');
        $this->line('The console will now show an empty platform: create your first real body at /admin/bodies.');

        return self::SUCCESS;
    }
}
