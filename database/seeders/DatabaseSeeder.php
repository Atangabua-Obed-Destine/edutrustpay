<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The default seed is the LIVE one: roles, permissions, and an operator account.
 * A real server is installed with `php artisan edutrustpay:install` instead, which
 * seeds roles alone and has the operator make their own account in the browser.
 *
 * Demo data is deliberately not here. `db:seed` is what somebody runs on a new
 * deployment, often without reading it first, and a stock command that quietly
 * invents an archdiocese with fabricated figures is how a demonstration ends up
 * being read as a report.
 *
 *   php artisan db:seed                                  a live, empty platform
 *   php artisan db:seed --class=DemoBodySeeder           add the worked example
 *   php artisan edutrustpay:clear-demo                   take it away again
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(PlatformSeeder::class);
    }
}
