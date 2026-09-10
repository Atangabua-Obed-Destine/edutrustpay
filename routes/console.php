<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Requires a cron entry calling `php artisan schedule:run` every minute.
|
| NOTE for whoever deploys this: posinnova wraps its entire schedule in
| `if ($env === 'live')`, which means nothing runs in any other environment and
| nobody finds out. Do not copy that idea here. The sweep is the only thing on
| the platform triggered by an ABSENCE of data — if it silently stops, the
| console keeps showing the last known position and every institution looks
| fine. `withoutOverlapping` and `onFailure` are there so a stuck run is visible
| rather than merely absent.
|
*/

Schedule::command('edutrustpay:sweep')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'The EdutrustPay staleness sweep failed. Until it runs, no not_reporting findings will be '
            .'raised and silent institutions will not appear on the console.'
        );
    });
