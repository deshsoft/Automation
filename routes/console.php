<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
 * On cPanel, one cron job runs everything:
 * * * * * * cd /home/USER/app && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('posts:publish-due')->everyMinute()->withoutOverlapping(10);

Schedule::command('queue:work --stop-when-empty --max-time=50 --timeout=900')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->runInBackground();

Schedule::command('posts:prune-media')->daily();

Schedule::command('downloads:prune')->hourly();

/*
 * YouTube and Facebook change often; a fresh yt-dlp keeps downloads working.
 */
Schedule::command('downloads:install')
    ->weekly()
    ->when(fn () => is_file(storage_path('app/bin/yt-dlp')));

/*
 * Lets the dashboard warn when the scheduler (and so publishing) is not running.
 */
Schedule::call(fn () => Cache::put('scheduler:last-run', now()->timestamp, now()->addDay()))
    ->everyMinute()
    ->name('scheduler-heartbeat');
