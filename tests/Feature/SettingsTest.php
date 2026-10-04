<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shows_defaults_and_disk_usage(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Automatic clean-up')
            ->assertSee('Disk usage')
            ->assertViewHas('settings', fn ($settings) => $settings['media_keep_days'] === 7 && $settings['posts_keep_days'] === 0);
    }

    public function test_settings_can_be_saved(): void
    {
        $this->actingAs(User::factory()->create())->put(route('settings.update'), [
            'media_keep_days' => 3,
            'posts_keep_days' => 30,
            'downloads_keep_days' => 2,
        ])->assertSessionHas('success');

        $this->assertSame(3, Setting::integer('media_keep_days'));
        $this->assertSame(30, Setting::integer('posts_keep_days'));
        $this->assertSame(2, Setting::integer('downloads_keep_days'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->put(route('settings.update'), [
            'media_keep_days' => 0,
            'posts_keep_days' => -1,
            'downloads_keep_days' => 'abc',
        ])->assertSessionHasErrors(['media_keep_days', 'posts_keep_days', 'downloads_keep_days']);
    }

    public function test_guests_cannot_change_settings(): void
    {
        $this->put(route('settings.update'), ['media_keep_days' => 1])->assertRedirect(route('login'));
    }

    public function test_old_finished_posts_are_deleted_when_enabled(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/old.jpg', 'x');
        Setting::put('posts_keep_days', 30);
        $old = Post::factory()->create(['status' => PostStatus::Published, 'media_path' => 'media/old.jpg', 'updated_at' => now()->subDays(31)]);
        $recent = Post::factory()->create(['status' => PostStatus::Published]);
        $scheduled = Post::factory()->scheduled()->create(['updated_at' => now()->subDays(60)]);

        $this->artisan('posts:prune')->expectsOutput('Deleted 1 old post(s).')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($scheduled);
        Storage::disk('public')->assertMissing('media/old.jpg');
    }

    public function test_posts_are_never_deleted_by_default(): void
    {
        $post = Post::factory()->create(['status' => PostStatus::Published, 'updated_at' => now()->subYears(2)]);

        $this->artisan('posts:prune')->expectsOutput('Automatic post deletion is off.');

        $this->assertModelExists($post);
    }

    public function test_media_clean_up_uses_the_saved_number_of_days(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/a.jpg', 'x');
        Setting::put('media_keep_days', 2);
        $post = Post::factory()->create(['status' => PostStatus::Published, 'media_path' => 'media/a.jpg', 'updated_at' => now()->subDays(3)]);

        $this->artisan('posts:prune-media')->assertSuccessful();

        $this->assertNull($post->fresh()->media_path);
    }

    public function test_clean_up_now_runs_every_clean_up(): void
    {
        $this->actingAs(User::factory()->create())->post(route('settings.clean-up'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Clean-up finished.'));
    }
}
