<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_own_posts(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->create(['caption' => 'My own post']);
        Post::factory()->create(['caption' => 'Not my post']);

        $this->actingAs($user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('My own post')
            ->assertDontSee('Not my post');
    }

    public function test_show_lists_each_account_result_and_error(): void
    {
        $account = SocialAccount::factory()->create(['name' => 'Ward 4 Page']);
        $post = Post::factory()->for($account->user)->create(['status' => PostStatus::Failed]);
        PostTarget::factory()->forPostAndAccount($post, $account)->create(['status' => TargetStatus::Failed, 'error' => 'Token expired']);

        $this->actingAs($account->user)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Ward 4 Page')
            ->assertSee('Token expired')
            ->assertSee('Retry failed')
            ->assertDontSee('Open all posts')
            ->assertDontSee('Tag yourself on these posts');
    }

    public function test_show_marks_main_page_and_links_to_published_posts(): void
    {
        $main = SocialAccount::factory()->facebook()->create(['name' => 'Main Page Name']);
        $sharer = SocialAccount::factory()->for($main->user)->facebook()->create();
        $post = Post::factory()->for($main->user)->create(['status' => PostStatus::Published, 'share_from_account_id' => $main->id]);
        PostTarget::factory()->forPostAndAccount($post, $main)->create([
            'status' => TargetStatus::Published,
            'published_at' => now(),
            'permalink' => 'https://www.facebook.com/main/posts/1',
        ]);
        PostTarget::factory()->forPostAndAccount($post, $sharer)->create(['status' => TargetStatus::Pending]);

        $this->actingAs($main->user)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('https://www.facebook.com/main/posts/1')
            ->assertSee('Main Page')
            ->assertSee('Shares the main post')
            ->assertSee('Open all posts (1)')
            ->assertSee('Tag me on all (1)');
    }

    public function test_cannot_view_another_users_post(): void
    {
        $post = Post::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('posts.show', $post))->assertForbidden();
    }

    public function test_retry_requeues_only_failed_targets(): void
    {
        Queue::fake();
        $account = SocialAccount::factory()->create();
        $otherAccount = SocialAccount::factory()->for($account->user)->create();
        $post = Post::factory()->for($account->user)->create(['status' => PostStatus::PartiallyFailed]);
        $failed = PostTarget::factory()->forPostAndAccount($post, $account)->create(['status' => TargetStatus::Failed, 'error' => 'x', 'state' => ['container_id' => 'c']]);
        PostTarget::factory()->forPostAndAccount($post, $otherAccount)->create(['status' => TargetStatus::Published]);

        $this->actingAs($account->user)->post(route('posts.retry', $post))->assertSessionHas('success', 'Retrying 1 account(s).');

        Queue::assertPushed(PublishPostTarget::class, 1);
        $this->assertSame(TargetStatus::Pending, $failed->fresh()->status);
        $this->assertNull($failed->fresh()->state);
        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);
    }

    public function test_cannot_retry_another_users_post(): void
    {
        Queue::fake();
        $post = Post::factory()->create(['status' => PostStatus::Failed]);

        $this->actingAs(User::factory()->create())->post(route('posts.retry', $post))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_deleting_a_scheduled_post_cancels_it(): void
    {
        $post = Post::factory()->scheduled()->create();

        $this->actingAs($post->user)->delete(route('posts.destroy', $post));

        $this->assertSame(PostStatus::Cancelled, $post->fresh()->status);
    }

    public function test_publishing_post_cannot_be_deleted(): void
    {
        $post = Post::factory()->create(['status' => PostStatus::Publishing]);

        $this->actingAs($post->user)->delete(route('posts.destroy', $post))->assertSessionHas('error');

        $this->assertModelExists($post);
    }

    public function test_finished_post_can_be_deleted(): void
    {
        $post = Post::factory()->create(['status' => PostStatus::Published]);

        $this->actingAs($post->user)->delete(route('posts.destroy', $post))->assertRedirect(route('posts.index'));

        $this->assertModelMissing($post);
    }

    public function test_cannot_delete_another_users_post(): void
    {
        $post = Post::factory()->create(['status' => PostStatus::Published]);

        $this->actingAs(User::factory()->create())->delete(route('posts.destroy', $post))->assertForbidden();

        $this->assertModelExists($post);
    }

    /**
     * Regression: on MySQL an update that changes nothing reports 0 affected
     * rows, which used to stop "post now" from queueing any job.
     */
    public function test_post_now_job_is_stored_in_the_real_database_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->freezeTime();
        $account = SocialAccount::factory()->create();

        $this->actingAs($account->user)->post(route('posts.store'), [
            'accounts' => [$account->id],
            'caption' => 'Post now',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('jobs', 1);
    }
}
