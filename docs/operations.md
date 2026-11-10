# Running EdutrustPay

Installing the console on a new server is covered in the README: clone, `composer install --no-dev`, `php artisan edutrustpay:install`.

Nothing on this platform works unattended unless these jobs are running. Pages
keep loading when they stop, and figures quietly stop changing, so the operator's
**System health** panel at `/admin` shows when they have stopped.

## The console (VPS)

Two things must always run:

```
# cron
* * * * * cd /path/to/console && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs the queue worker and the daily silence check, so cron alone is
enough. For faster processing, run `php artisan queue:work` under Supervisor and
remove the `queue:work` line from `routes/console.php`.

Also needed: PHP-FPM behind HTTPS, MySQL with backups, and `APP_KEY` backed up
**separately** from the database. Together they decrypt every school's API secret.

## A school

A school's machine sends its own reports, so it needs its own jobs. Reporting never
uses the cloudflared tunnel. The tunnel is only for browser links into the school.

1. The body adds the school under **All institutions** and issues a key.
2. On the school's **EdutrustPay Reporting** settings page, enter the four values
   shown, tick **Send reports**, save and click **Test connection**.
3. File its history: `php artisan edutrustpay:report --months=12 --send`, then `php artisan edutrustpay:daybook --months=12 --send` and `php artisan edutrustpay:budget-sheet --months=12 --send`
4. Make sure its jobs run:
   - **Linux:** the usual `schedule:run` cron line.
   - **Windows:** Task Scheduler running `schedule:run`, or for reporting alone,
     `scripts\windows\install-tasks.ps1 -Schools <path to the school>`.
5. Check it: `php artisan edutrustpay:doctor`

**`schedule:run` runs everything the school has scheduled**, including fee reminders
to parents and late-fee fines. Turn it on only when the school is really live. A test
copy should use `install-tasks.ps1`, which runs the EdutrustPay commands alone.

| Command | How often | What it does |
|---|---|---|
| `edutrustpay:report --months=3` | Daily | Files new months and refiles any that changed |
| `edutrustpay:daybook --months=3` | Daily | Files each month's daybook, and refiles any that changed. These lines name people |
| `edutrustpay:budget-sheet --months=3` | Daily | Files the budget sheet at the end of each month |
| `edutrustpay:flush` | Hourly | Delivers what is waiting, and retries |
| `edutrustpay:heartbeat` | Daily | Tells the console the school is alive |

## Running the tests

`php artisan test` refuses to run while the config is cached, because a cached config
would point the tests at the real database. Run `php artisan config:clear` first.
