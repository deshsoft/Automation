<?php

namespace App\Providers;

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
        //
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
