<?php

namespace App\Providers;

use App\Services\Downloading\PhotoFallback;
use App\Services\SystemDiagnostics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per job, so its lastError can be read after a failed call.
        $this->app->singleton(PhotoFallback::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Some shared hosts have broken IPv6 routing, so calls to Facebook/Google time out.
        if (SystemDiagnostics::forcesIpv4()) {
            Http::globalOptions(['force_ip_resolve' => 'v4']);
        }
    }
}
