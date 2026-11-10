<?php

namespace App\Console\Commands;

use App\Services\PlatformInstaller;
use App\Support\EnvFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;

/**
 * Prepares a freshly cloned server for the console, then hands over to the
 * browser for the operator account and the first organisation.
 *
 * Shaped after edutrustshsm's edutrust:install, so a technician who has
 * installed one has installed both. It never touches a console that is already
 * running: it stops if the database already has accounts, and can be run again
 * after a failure.
 */
class InstallPlatform extends Command
{
    protected $signature = 'edutrustpay:install
        {--url= : The address people will use to open the console}
        {--db-host= : Database server}
        {--db-port= : Database port}
        {--db-database= : Database name, created if it does not exist}
        {--db-username= : Database user}
        {--db-password= : Database password}';

    protected $description = 'Install the EdutrustPay console on this server';

    private const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'dom', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml',
    ];

    public function handle(PlatformInstaller $installer): int
    {
        $this->newLine();
        $this->line('  <options=bold>'.platform_name().'</>, powered by I-NNOVA');
        $this->newLine();

        if ($installer->isInstalled()) {
            $this->components->error('This console is already installed. Nothing was changed.');

            return self::FAILURE;
        }

        // A cached configuration would ignore everything written to .env below.
        $this->callSilent('config:clear');

        if ($problems = $this->serverProblems()) {
            $this->components->error('This server is not ready yet:');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $url = $this->askUrl();

        $database = $this->connectToDatabase();
        if ($database === null) {
            return self::FAILURE;
        }

        if ($reason = $this->whyDatabaseCannotBeUsed($installer)) {
            $this->components->error($reason.' Nothing was changed.');

            return self::FAILURE;
        }

        $this->writeEnvironment($url, $database);

        $this->newLine();
        $this->components->task('Creating the tables', fn () => $this->callSilent('migrate', ['--force' => true]) === 0);
        $this->components->task('Adding the roles and permissions', fn () => $this->seed());
        $this->components->task('Linking uploaded files', fn () => $this->linkStorage());
        $this->components->task('Building the screens', fn () => $this->buildAssets());

        $link = $url.'/install/'.$installer->issueToken();

        $this->newLine();
        $this->components->info('The server is ready. One step left, in a browser:');
        $this->line('  <options=bold>'.$link.'</>');
        $this->newLine();
        $this->line('  Open it to create the operator account and the first organisation. The link works once.');
        $this->line('  If it gets lost, run this command again for a new one.');
        $this->newLine();
        $this->line('  Then make sure the scheduler runs every minute, or reports will arrive and never be read:');
        $this->line('  <fg=gray>'.$this->schedulerLine().'</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function serverProblems(): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $problems[] = 'PHP 8.2 or newer is required. This is PHP '.PHP_VERSION.'.';
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = "The PHP extension \"{$extension}\" is off. Turn it on in ".(php_ini_loaded_file() ?: 'php.ini').'.';
            }
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $folder) {
            if (! is_writable($folder)) {
                $problems[] = "The folder {$folder} must be writable.";
            }
        }

        if (! File::exists(base_path('vendor/edutrustpay/reporting-contract'))) {
            $problems[] = 'The reporting contract is missing. Run "composer install --no-dev" in this folder first.';
        }

        if (! File::exists(public_path('build/manifest.json')) && Process::run('npm --version')->failed()) {
            $problems[] = 'Node.js is needed to build the screens. Install it, then run this command again.';
        }

        return $problems;
    }

    private function askUrl(): string
    {
        $url = $this->option('url') ?: $this->ask(
            'What address will people use to open the console? (on a VPS, its https:// domain)',
            $this->guessUrl()
        );

        return rtrim(str_contains($url, '://') ? $url : 'http://'.$url, '/');
    }

    /** XAMPP and plain Apache serve a cloned folder at <server>/<folder>/public. */
    private function guessUrl(): string
    {
        $ip = gethostbyname(gethostname());
        $inWebRoot = in_array(basename(dirname(base_path())), ['htdocs', 'www', 'html'], true);

        return 'http://'.$ip.($inWebRoot ? '/'.basename(base_path()).'/public' : '');
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string}|null */
    private function connectToDatabase(): ?array
    {
        do {
            $database = [
                'host' => $this->option('db-host') ?: $this->ask('Database server', env('DB_HOST', '127.0.0.1')),
                'port' => $this->option('db-port') ?: $this->ask('Database port', env('DB_PORT', '3306')),
                'database' => $this->option('db-database') ?: $this->ask('Database name (created if it does not exist)', env('DB_DATABASE') && env('DB_DATABASE') !== 'laravel' ? env('DB_DATABASE') : 'edutrustpay'),
                'username' => $this->option('db-username') ?: $this->ask('Database user', env('DB_USERNAME', 'root')),
                'password' => $this->option('db-password') ?? ((string) $this->secret('Database password (leave empty if there is none)')),
            ];

            $error = $this->openDatabase($database);

            if ($error === null) {
                config([
                    'database.default' => 'mysql',
                    'database.connections.mysql.host' => $database['host'],
                    'database.connections.mysql.port' => $database['port'],
                    'database.connections.mysql.database' => $database['database'],
                    'database.connections.mysql.username' => $database['username'],
                    'database.connections.mysql.password' => $database['password'],
                ]);
                DB::purge('mysql');
                DB::setDefaultConnection('mysql');

                return $database;
            }

            $this->components->error($error);
        } while ($this->input->isInteractive() && $this->confirm('Try different database details?', true));

        return null;
    }

    /** Connects, and creates the database if it is not there yet. */
    private function openDatabase(array $database): ?string
    {
        if (! preg_match('/^\w+$/', $database['database'])) {
            return 'A database name can only use letters, numbers and underscores.';
        }

        try {
            $pdo = new PDO(
                "mysql:host={$database['host']};port={$database['port']};charset=utf8mb4",
                $database['username'],
                $database['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            return 'Could not open the database: '.$e->getMessage();
        }

        return null;
    }

    private function whyDatabaseCannotBeUsed(PlatformInstaller $installer): ?string
    {
        if ($installer->isInstalled()) {
            return 'This database already has accounts, so it belongs to a running console.';
        }

        // Empty, or an install of ours that stopped part-way, can both continue.
        // getTables() with no schema lists every database on the server.
        if (Schema::getTables(DB::connection()->getDatabaseName()) !== [] && ! Schema::hasTable('migrations')) {
            return 'This database holds tables from something else. Choose an empty one.';
        }

        return null;
    }

    private function writeEnvironment(string $url, array $database): void
    {
        $path = base_path('.env');

        if (! File::exists($path)) {
            File::copy(base_path('.env.example'), $path);
        }

        $values = [
            'APP_NAME' => 'EdutrustPay',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'],
            'DB_PORT' => $database['port'],
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'],
            // Reports are processed on the queue; the scheduler runs the worker.
            'QUEUE_CONNECTION' => 'database',
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'LOG_LEVEL' => 'warning',
        ];

        // A key already set must stay: it decrypts every school's API secret.
        if (! preg_match('/^APP_KEY=.+$/m', File::get($path))) {
            $values['APP_KEY'] = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
            config(['app.key' => $values['APP_KEY']]);
        }

        EnvFile::set($path, $values);
        config(['app.url' => $url]);
    }

    /**
     * Roles and permissions only. The operator account is made on the setup
     * page, so no default email and no printed password ever exist.
     */
    private function seed(): bool
    {
        return $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]) === 0;
    }

    private function linkStorage(): bool
    {
        if (! file_exists(public_path('storage'))) {
            $this->callSilent('storage:link');
        }

        return file_exists(public_path('storage'));
    }

    private function buildAssets(): bool
    {
        if (File::exists(public_path('build/manifest.json'))) {
            return true;
        }

        $process = Process::path(base_path())->timeout(900);

        return $process->run('npm ci')->successful()
            && $process->run('npm run build')->successful();
    }

    private function schedulerLine(): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? 'schtasks /create /tn "EdutrustPay scheduler" /sc minute /mo 1 /ru SYSTEM /tr "'.PHP_BINARY.' '.base_path('artisan').' schedule:run"'
            : '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1   (in crontab -e)';
    }
}
