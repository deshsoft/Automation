<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishDuePostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_due_scheduled_posts_are_queued(): void
    {
        Queue::fake();
        $account = SocialAccount::factory()->create();
        $due = Post::factory()->for($account->user)->scheduled(now()->subMinute())->create();
        $future = Post::factory()->for($account->user)->scheduled(now()->addHour())->create();
        $cancelled = Post::factory()->for($account->user)->create(['status' => PostStatus::Cancelled, 'scheduled_at' => now()->subMinute()]);
        foreach ([$due, $future, $cancelled] as $post) {
            PostTarget::factory()->forPostAndAccount($post, $account)->create();
        }

        $this->artisan('posts:publish-due')->expectsOutput('Started 1 scheduled post(s).')->assertSuccessful();

        Queue::assertPushed(PublishPostTarget::class, 1);
        Queue::assertPushed(PublishPostTarget::class, fn (PublishPostTarget $job) => $job->target->post_id === $due->id);
        $this->assertSame(PostStatus::Publishing, $due->fresh()->status);
        $this->assertSame(PostStatus::Scheduled, $future->fresh()->status);
        $this->assertSame(PostStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_running_twice_does_not_queue_the_same_post_again(): void
    {
        Queue::fake();
        $account = SocialAccount::factory()->create();
        $post = Post::factory()->for($account->user)->scheduled(now()->subMinute())->create();
        PostTarget::factory()->forPostAndAccount($post, $account)->create();

        $this->artisan('posts:publish-due');
        $this->artisan('posts:publish-due');

        Queue::assertPushed(PublishPostTarget::class, 1);
    }

    public function test_prune_media_deletes_files_of_old_finished_posts_only(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/old.jpg', 'x');
        Storage::disk('public')->put('media/new.jpg', 'x');
        $old = Post::factory()->create(['status' => PostStatus::Published, 'media_path' => 'media/old.jpg', 'updated_at' => now()->subDays(8)]);
        $recent = Post::factory()->create(['status' => PostStatus::Published, 'media_path' => 'media/new.jpg']);

        $this->artisan('posts:prune-media')->assertSuccessful();

        Storage::disk('public')->assertMissing('media/old.jpg');
        Storage::disk('public')->assertExists('media/new.jpg');
        $this->assertNull($old->fresh()->media_path);
        $this->assertSame('media/new.jpg', $recent->fresh()->media_path);
    }
}
