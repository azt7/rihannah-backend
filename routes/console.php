<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Here you may define all of your scheduled tasks. The tasks are run
| at the specified intervals by the Laravel scheduler.
|
| To run the scheduler, add this cron entry to your server:
| * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Auto-cancel expired tentative bookings every 15 minutes (US-SYS-02)
Schedule::command('bookings:cancel-expired-tentative')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
