@php($lastRun = \Illuminate\Support\Facades\Cache::get('scheduler:last-run'))
@if ($lastRun === null || now()->timestamp - $lastRun > 180)
    <div class="mb-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
        <strong>⚠️ Posts are not being published:</strong> the background scheduler is not running.
        Open a Terminal and run <code class="rounded bg-red-100 px-1">php artisan schedule:work</code> in the project folder (keep the window open).
        On cPanel, check the Cron Job.
    </div>
@endif
