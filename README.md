# EdutrustPay

**EdutrustPay, powered by I-NNOVA.** The oversight console for the organisations that run schools. Each school's accounting system sends its monthly figures here, signed. A diocese then sees its schools in one place, checked against its own rules. The console only reads what schools send; it never changes their books.

This guide is for the technician installing the console on a server.

## What the server needs

- PHP 8.2 or newer, with these extensions on: `bcmath`, `ctype`, `dom`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`
- MySQL or MariaDB
- Apache or Nginx, with HTTPS on a VPS
- Composer, Node.js and Git
- Internet access during the installation

XAMPP provides PHP, MariaDB and Apache, which is enough to try the console on one computer.

## Install

Clone into the web folder (`/var/www` on Linux, `C:\xampp\htdocs` on XAMPP), then:

```
git clone https://github.com/Atangabua-Obed-Destine/edutrustpay.git
cd edutrustpay
composer install --no-dev
php artisan edutrustpay:install
```

The installer checks the server, asks for the console's address and the database details, then sets everything up. It creates the database if it does not exist. On Windows, run it in PowerShell or Command Prompt rather than Git Bash, which can hang on the password question.

**The address** is what people type to open the console, and what every school's system sends its reports to.

- **On a VPS:** use its HTTPS domain, for example `https://console.example.org`, and point the web server's document root at the `public` folder. Linux also needs `storage` and `bootstrap/cache` writable by the web server's user.
- **On XAMPP:** the installer suggests `http://<this computer's address>/edutrustpay/public`.

The installer finishes by printing a private link. Open it in a browser:

1. Create the **operator** account. It sees every organisation and creates all the other accounts.
2. Optionally, name the **first organisation**, for example the Archdiocese of Bamenda. You can also add it later.

You arrive on the operator's dashboard, signed in. From there:

1. Open the organisation under **Bodies** and invite its administrator under **People**.
2. That administrator, or you, adds its schools under **All institutions** and issues each one a connection key.
3. Each school then enters its key in its own system. See `docs/operations.md`.

The link works once. If it gets lost, run `php artisan edutrustpay:install` again for a new one. If an installation stops part-way, fix the problem and run the same command. It never touches a console that already has accounts.

## After installing

**The scheduler must run every minute.** Reports from schools are processed on a queue that the scheduler runs, together with the daily check for schools that have gone quiet. Without it, reports arrive and are never read, and nothing on the screen says so.

Linux, in `crontab -e`:

```
* * * * * cd /var/www/edutrustpay && php artisan schedule:run >> /dev/null 2>&1
```

Windows, in an administrator PowerShell:

```
schtasks /create /tn "EdutrustPay scheduler" /sc minute /mo 1 /ru SYSTEM /tr "C:\xampp\php\php.exe C:\xampp\htdocs\edutrustpay\artisan schedule:run"
```

The operator's dashboard shows a **System health** panel that turns red when these jobs stop.

**Backups.** Copy the database daily (`mysqldump`) and keep the copies away from the server. Back up `APP_KEY` from `.env` **separately**: together with the database it decrypts every school's connection secret.

**Email.** Invitations are shown on screen as a link to pass on, so the console works without a mail server.

## Updating

Back up first, then in the console's folder:

```
git pull
composer install --no-dev
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
```

## If the operator is locked out

There is no password reset by email yet. On the server, run this with the account's email and a new password, then ask them to sign in:

```
php artisan tinker --execute="App\Models\User::where('email', 'name@example.org')->firstOrFail()->update(['password' => Hash::make('a-new-password')]);"
```

## For developers

Copy `.env.example` to `.env`, set `APP_ENV=local` and `APP_DEBUG=true`, then run `composer install`, `php artisan edutrustpay:install`, and `composer run dev`.

- `php artisan db:seed --class=DemoBodySeeder` adds a worked example: an archdiocese, deaneries and schools with invented figures. `php artisan edutrustpay:clear-demo` takes it away again.
- `php artisan test` runs the tests on an in-memory database. Run `php artisan config:clear` first.
- `packages/reporting-contract` is the reporting format the schools' systems send. The school systems keep a copy of it; copy it to them again when it changes.
- `docs/operations.md` covers running the console and connecting a school.
