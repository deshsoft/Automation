<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpSettingsTest extends TestCase
{
    public function test_outgoing_requests_can_be_forced_to_ipv4(): void
    {
        config(['services.http.force_ipv4' => true]);
        $this->app->instance(Factory::class, new Factory);
        Http::clearResolvedInstances();

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('v4', Http::getOptions()['force_ip_resolve'] ?? null);
    }

    public function test_ipv4_is_not_forced_by_default(): void
    {
        $this->assertArrayNotHasKey('force_ip_resolve', Http::getOptions());
    }
}
