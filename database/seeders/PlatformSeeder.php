<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Everything a LIVE platform needs and nothing it does not: roles, permissions,
 * and one operator account to sign in with.
 *
 * This exists because the only account that could reach /admin was created by
 * DemoBodySeeder, which meant clearing the demo data also removed the way in.
 * "You can have demo data or you can have a login" is not a choice anybody
 * should have to make on the day they go live.
 *
 * The password is NOT 'password'. It comes from config('platform.operator'),
 * or is generated and printed once. Read through config rather than env()
 * directly, because this console runs with its configuration CACHED and a
 * stray env() call would then silently return null - see config/platform.php.
 * A seeded default credential on a system holding
 * an archdiocese's consolidated finances is the kind of thing that survives
 * unnoticed into production for years.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $email = config('platform.operator.email');
        $existing = User::where('email', $email)->first();

        if ($existing) {
            // Never silently reset a live operator's password on a re-run.
            $this->command?->info('Operator '.$email.' already exists; left untouched.');

            return;
        }

        $configured = config('platform.operator.password');
        $password = $configured ?: Str::password(16, symbols: false);

        User::create([
            'name' => config('platform.operator.name'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'super_admin',
            // The operator belongs to no body: they are outside every one.
            'body_id' => null,
        ]);

        $this->command?->newLine();
        $this->command?->info('Operator account created.');
        $this->command?->line('  email:    '.$email);
        $this->command?->line('  password: '.$password);

        if (! $configured) {
            $this->command?->warn('  This password was generated and is shown once. Change it after signing in.');
        }
    }
}
