<?php

namespace Tests\Feature\Install;

use App\Models\Body;
use App\Models\User;
use App\Services\PlatformInstaller;
use App\Support\EnvFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A technician clones the console onto a server, runs the installer, and
 * creates the operator from its one-time link. Nobody who can reach the server
 * may get there first: the operator account sees every organisation's money.
 */
class FirstRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // What the installer seeds: roles and permissions, and no account.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** @return array<string, string> */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Operator',
            'email' => 'ada@innova.test',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'organisation_name' => 'Archdiocese of Bamenda',
            'organisation_type' => 'Archdiocese',
        ], $overrides);
    }

    public function test_an_empty_console_sends_the_login_page_to_its_setup(): void
    {
        $this->assertSame(0, User::count());

        $this->get(route('login'))->assertRedirect(route('install.show'));
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_setup_stays_closed_without_the_installers_link(): void
    {
        app(PlatformInstaller::class)->issueToken();

        $this->get(route('install.show'))->assertOk()->assertSee('php artisan edutrustpay:install')->assertDontSee('name="email"', false);
        $this->get(route('install.show', 'wrong-token'))->assertOk()->assertDontSee('name="email"', false);
        $this->post(route('install.store', 'wrong-token'), $this->details())->assertForbidden();

        $this->assertSame(0, User::count());
    }

    public function test_the_link_opens_a_page_that_says_who_makes_the_console(): void
    {
        $token = app(PlatformInstaller::class)->issueToken();

        $this->get(route('install.show', $token))
            ->assertOk()
            ->assertSee('EdutrustPay')
            ->assertSee('I-NNOVA')
            ->assertSee('name="email"', false);
    }

    public function test_the_operator_and_the_first_organisation_are_created_and_signed_in(): void
    {
        $token = app(PlatformInstaller::class)->issueToken();

        $this->post(route('install.store', $token), $this->details())
            ->assertRedirect(route('admin.dashboard'));

        $operator = User::sole();
        $this->assertSame('super_admin', $operator->role);
        $this->assertNull($operator->body_id, 'The operator belongs to no body.');
        $this->assertTrue(Hash::check('a-long-password', $operator->password));
        $this->assertSame($operator->id, Auth::id());

        $body = Body::sole();
        $this->assertSame('Archdiocese of Bamenda', $body->name);
        $this->assertSame('Archdiocese', $body->type);

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_organisation_can_be_left_for_later(): void
    {
        $token = app(PlatformInstaller::class)->issueToken();

        $this->post(route('install.store', $token), $this->details(['organisation_name' => '', 'organisation_type' => '']))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'add the first organisation under Bodies'));

        $this->assertSame(0, Body::count());
        $this->assertSame(1, User::count());
    }

    public function test_the_link_works_once(): void
    {
        $token = app(PlatformInstaller::class)->issueToken();
        $this->post(route('install.store', $token), $this->details());
        Auth::logout();

        $this->get(route('install.show', $token))->assertRedirect(route('login'));
        $this->post(route('install.store', $token), $this->details(['email' => 'late@innova.test']))
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::count());
        $this->assertFalse(app(PlatformInstaller::class)->tokenIsValid($token));
        $this->get(route('login'))->assertOk();
    }

    public function test_bad_details_are_refused_and_create_nothing(): void
    {
        $token = app(PlatformInstaller::class)->issueToken();

        $this->post(route('install.store', $token), $this->details([
            'email' => 'not an email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]))->assertSessionHasErrors(['email', 'password']);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Body::count());
        $this->assertTrue(app(PlatformInstaller::class)->tokenIsValid($token));
    }

    public function test_seeding_after_an_install_adds_no_second_operator(): void
    {
        app(PlatformInstaller::class)->finish(
            ['name' => 'Ada Operator', 'email' => 'ada@innova.test', 'password' => 'a-long-password'],
            ['name' => null, 'type' => null],
        );

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(1, User::where('role', 'super_admin')->count());
        $this->assertSame('ada@innova.test', User::sole()->email);
    }

    public function test_the_installer_will_not_touch_a_running_console(): void
    {
        $envBefore = File::exists(base_path('.env')) ? File::get(base_path('.env')) : null;

        app(PlatformInstaller::class)->finish(
            ['name' => 'Ada Operator', 'email' => 'ada@innova.test', 'password' => 'a-long-password'],
            ['name' => null, 'type' => null],
        );

        $this->artisan('edutrustpay:install')
            ->expectsOutputToContain('already installed')
            ->assertFailed();

        $this->assertSame($envBefore, File::exists(base_path('.env')) ? File::get(base_path('.env')) : null);
        $this->assertSame(1, User::count());
    }

    public function test_settings_are_written_without_disturbing_the_rest_of_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        File::put($path, "APP_NAME=Laravel\n# DB_HOST=127.0.0.1\nMAIL_MAILER=log\n");

        EnvFile::set($path, [
            'APP_NAME' => 'EdutrustPay',
            'DB_HOST' => '10.0.0.5',
            'DB_PASSWORD' => 'pa$$ w#rd',
        ]);

        $this->assertSame(
            "APP_NAME=EdutrustPay\nDB_HOST=10.0.0.5\nMAIL_MAILER=log\nDB_PASSWORD='pa$$ w#rd'\n",
            File::get($path)
        );

        File::delete($path);
    }
}
