<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Going live: taking the worked example away without taking the platform with
 * it.
 *
 * A destructive command tested only by reading it is a command nobody will dare
 * run, which means the demo data stays and eventually somebody quotes a
 * consolidated total that includes it.
 */
class ClearDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
    }

    public function test_it_removes_the_demo_bodies_and_leaves_the_operator_able_to_sign_in(): void
    {
        $this->seed(\Database\Seeders\DemoBodySeeder::class);

        $this->assertGreaterThan(0, Body::count());
        $operatorId = User::where('role', 'super_admin')->firstOrFail()->id;

        $this->artisan('edutrustpay:clear-demo --force')->assertSuccessful();

        $this->assertSame(0, Body::count());
        $this->assertSame(0, Institution::withoutBodyScope()->count());
        $this->assertSame(0, PeriodReport::withoutBodyScope()->count());
        $this->assertSame(0, User::where('email', 'bursar@bamenda.test')->count());

        // The point of the whole exercise: the way in survives.
        $this->assertDatabaseHas('users', ['id' => $operatorId, 'role' => 'super_admin']);
        $this->assertGreaterThan(0, \App\Models\Permission::count());
    }

    /**
     * The guard that makes the command safe to leave in the codebase.
     */
    public function test_it_refuses_when_a_real_account_lives_inside_a_demo_body(): void
    {
        $this->seed(\Database\Seeders\DemoBodySeeder::class);

        $bamenda = Body::where('slug', 'archdiocese-of-bamenda')->firstOrFail();

        User::create([
            'name' => 'A Real Bursary Head',
            'email' => 'real.person@archdioceseofbamenda.org',
            'password' => 'password',
            'role' => 'body_user',
            'body_id' => $bamenda->id,
        ]);

        $this->artisan('edutrustpay:clear-demo --force')->assertFailed();

        $this->assertDatabaseHas('bodies', ['slug' => 'archdiocese-of-bamenda']);
    }

    /**
     * The guard that matters most, and the one the first version missed.
     *
     * The quickest way to try the platform is to point a real school at a
     * seeded institution rather than create a body first — which is exactly
     * what happened on the development database: "Catholic University of
     * Bamenda" is a seeded name with a real institute's live ledger behind it.
     * Clearing the demo would have destroyed reports that cannot be
     * regenerated, and no user account existed to warn anybody.
     */
    public function test_it_refuses_when_a_real_system_has_signed_into_a_demo_body(): void
    {
        $this->seed(\Database\Seeders\DemoBodySeeder::class);

        $credential = \App\Models\InstitutionCredential::query()->firstOrFail();

        // A credential that has actually been used. Nothing a seeder does can
        // produce this: it means something out there holds the secret and has
        // successfully signed with it.
        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        $this->artisan('edutrustpay:clear-demo --force')->assertFailed();

        $this->assertDatabaseHas('bodies', ['slug' => 'archdiocese-of-bamenda']);
        $this->assertGreaterThan(0, Institution::withoutBodyScope()->count(), 'The connected institution survives.');
    }

    /**
     * The escape hatch. Starting from a genuinely clean platform is a
     * legitimate thing to want, and refusing forever would only push somebody
     * towards deleting rows by hand, which is worse.
     */
    public function test_discard_connected_overrides_the_guard(): void
    {
        $this->seed(\Database\Seeders\DemoBodySeeder::class);

        \App\Models\InstitutionCredential::query()->firstOrFail()
            ->forceFill(['last_used_at' => now()])->saveQuietly();

        $this->artisan('edutrustpay:clear-demo --force --discard-connected')->assertSuccessful();

        $this->assertSame(0, Body::count());
        $this->assertSame(0, Institution::withoutBodyScope()->count());

        // The way back in survives even the most destructive path.
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    /**
     * An issued-but-never-used credential is just onboarding that has not
     * happened yet, and must not block the clear-out.
     */
    public function test_an_unused_credential_does_not_block_it(): void
    {
        $this->seed(\Database\Seeders\DemoBodySeeder::class);

        \App\Models\InstitutionCredential::query()->update(['last_used_at' => null]);

        $this->artisan('edutrustpay:clear-demo --force')->assertSuccessful();

        $this->assertSame(0, Body::count());
    }

    public function test_the_default_seed_produces_a_live_platform_with_no_demo_data(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(0, Body::count(), 'db:seed must not invent an archdiocese.');
        $this->assertSame(1, User::where('role', 'super_admin')->count());
        $this->assertGreaterThan(0, \App\Models\Permission::count());
    }

    /**
     * A seeded default password on a system holding an archdiocese's
     * consolidated finances is the kind of thing that survives unnoticed into
     * production.
     */
    public function test_the_operator_password_is_not_the_word_password(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $operator = User::where('role', 'super_admin')->firstOrFail();

        $this->assertFalse(
            \Illuminate\Support\Facades\Hash::check('password', $operator->password)
        );
    }
}
