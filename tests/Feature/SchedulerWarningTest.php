<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_warning_is_shown_when_the_scheduler_has_not_run(): void
    {
        $this->actingAs(User::factory()->create())->get(route('posts.index'))
            ->assertSee('the background scheduler is not running');
    }

    public function test_warning_is_shown_when_the_scheduler_stopped_minutes_ago(): void
    {
        Cache::put('scheduler:last-run', now()->subMinutes(5)->timestamp);

        $this->actingAs(User::factory()->create())->get(route('posts.index'))
            ->assertSee('the background scheduler is not running');
    }

    public function test_no_warning_while_the_scheduler_is_running(): void
    {
        Cache::put('scheduler:last-run', now()->subSeconds(30)->timestamp);

        $this->actingAs(User::factory()->create())->get(route('posts.index'))
            ->assertDontSee('the background scheduler is not running');
    }
}
