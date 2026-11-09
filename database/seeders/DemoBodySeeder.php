<?php

namespace Database\Seeders;

use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCapability;
use App\Models\InstitutionCredential;
use App\Models\Role;
use App\Models\User;
use EdutrustPay\Contract\Capability;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The founding case, as something you can actually click through.
 *
 * The Archdiocese of Bamenda with two deaneries beneath it, and institutions on
 * all three source systems — deliberately including the honest capability
 * profiles each system can support TODAY:
 *
 *   paxhitestbed  — everything. Its accounting services already produce every
 *                   figure the contract asks for.
 *   edutrustshsm  — ledger only. Mid-build; receivables and the rest arrive over
 *                   the coming months.
 *   posinnova     — ledger and receivables, derived from `transactions`. No
 *                   budget, no integrity, no bank_recon, because its
 *                   double-entry ledger has never been written to at all.
 *
 * That spread is the point: a body whose institutions all reported everything
 * would never exercise the one rule that matters.
 */
class DemoBodySeeder extends Seeder
{
    public function run(): void
    {
        $archdiocese = Body::updateOrCreate(
            ['slug' => 'archdiocese-of-bamenda'],
            [
                'name' => 'Archdiocese of Bamenda',
                'type' => 'archdiocese',
                'city' => 'Bamenda',
                'region' => 'Northwest',
                'country' => 'Cameroon',
                // The Archdiocese's own books run January-December, but its
                // schools run July-June. The console assembles both from months.
                'fiscal_year_start_month' => 1,
            ]
        );

        $central = Body::updateOrCreate(
            ['slug' => 'bamenda-central-deanery'],
            ['name' => 'Bamenda Central Deanery', 'type' => 'deanery', 'parent_body_id' => $archdiocese->id]
        );

        $kumbo = Body::updateOrCreate(
            ['slug' => 'kumbo-deanery'],
            ['name' => 'Kumbo Deanery', 'type' => 'deanery', 'parent_body_id' => $archdiocese->id]
        );

        // A second, unrelated body. Exists so that "can a body user see another
        // body's data" is a question anyone can check by logging in, not only
        // something the test suite asserts.
        $buea = Body::updateOrCreate(
            ['slug' => 'diocese-of-buea'],
            ['name' => 'Diocese of Buea', 'type' => 'diocese', 'city' => 'Buea', 'region' => 'Southwest']
        );

        $institutions = [
            [$central, 'Catholic University of Bamenda', 'school_higher', 'paxhitestbed', [
                Capability::LEDGER, Capability::RECEIVABLES, Capability::PAYABLES,
                Capability::BUDGET, Capability::PAYROLL, Capability::INTEGRITY,
                Capability::ENROLMENT, Capability::BANK_RECON,
            ]],
            [$central, 'St Bede College Ashing', 'school_secondary', 'edutrustshsm', [
                Capability::LEDGER,
            ]],
            [$kumbo, 'Shisong Cardiac Centre Pharmacy', 'health', 'posinnova', [
                Capability::LEDGER, Capability::RECEIVABLES,
            ]],
            [$kumbo, 'St Augustine\'s College Kumbo', 'school_secondary', 'edutrustshsm', [
                Capability::LEDGER,
            ]],
            [$buea, 'Buea Diocesan Clinic', 'health', 'posinnova', [
                Capability::LEDGER,
            ]],
        ];

        foreach ($institutions as [$body, $name, $type, $system, $capabilities]) {
            $institution = Institution::withoutBodyScope()->updateOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($name)],
                ['body_id' => $body->id, 'name' => $name, 'type' => $type, 'system' => $system]
            );

            foreach ($capabilities as $capability) {
                InstitutionCapability::updateOrCreate(
                    ['institution_id' => $institution->id, 'capability' => $capability],
                    ['source' => 'declared', 'declared_at' => now()]
                );
            }

            if ($institution->credentials()->count() === 0) {
                $issued = InstitutionCredential::issue($institution, 'Seeded credential');

                $this->command?->line(sprintf(
                    '  %-36s key=%s secret=%s',
                    $institution->name,
                    $issued['credential']->key_id,
                    $issued['secret']
                ));
            }
        }

        $this->seedUsers($archdiocese, $central, $buea);

        $this->command?->info('Demo body tree seeded. Secrets above are shown once and are not recoverable.');
    }

    private function seedUsers(Body $archdiocese, Body $central, Body $buea): void
    {
        // The operator account is NOT created here any more. It belongs to the
        // platform, not to the demonstration — clearing the demo data used to
        // remove the only way to sign in. See PlatformSeeder.
        $this->call(PlatformSeeder::class);

        $people = [
            ['bursar@bamenda.test', 'Archdiocesan Bursary Head', $archdiocese, 'bursary_head'],
            ['auditor@bamenda.test', 'Archdiocesan Auditor', $archdiocese, 'auditor'],
            ['deanery@bamenda.test', 'Central Deanery Bursar', $central, 'bursary_head'],
            ['bursar@buea.test', 'Buea Bursary Head', $buea, 'bursary_head'],
        ];

        foreach ($people as [$email, $name, $body, $roleName]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'role' => $roleName, 'body_id' => $body->id]
            );

            if ($role = Role::where('name', $roleName)->first()) {
                $user->roles()->sync([$role->id]);
            }
        }

        $this->command?->line('  Demo body users: password "password". They exist only for the worked example.');
    }
}
