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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public');
    }

    public function test_scheduled_post_can_be_edited_and_rescheduled(): void
    {
        $this->travelTo('2026-10-05 00:00:00');
        $page = SocialAccount::factory()->facebook()->create();
        $post = Post::factory()->for($page->user)->scheduled(now()->addDay())->create(['caption' => 'Old text']);
        PostTarget::factory()->forPostAndAccount($post, $page)->create();

        $this->actingAs($page->user)->get(route('posts.edit', $post))->assertOk()->assertSee('Old text');

        $this->actingAs($page->user)->put(route('posts.update', $post), [
            'caption' => 'New text',
            'captions' => ['facebook' => 'Facebook only text'],
            'when' => 'schedule',
            'scheduled_at' => '2026-10-07T18:30',
        ])->assertRedirect(route('posts.show', $post));

        $post->refresh();
        $this->assertSame('New text', $post->caption);
        $this->assertSame('Facebook only text', $post->option('captions.facebook'));
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame('2026-10-07 12:30:00', $post->scheduled_at->utc()->toDateTimeString());
        Queue::assertNothingPushed();
    }

    public function test_failed_post_is_edited_and_only_failed_accounts_retry(): void
    {
        $failedPage = SocialAccount::factory()->facebook()->create();
        $donePage = SocialAccount::factory()->for($failedPage->user)->facebook()->create();
        $post = Post::factory()->for($failedPage->user)->create(['status' => PostStatus::PartiallyFailed, 'caption' => 'Typo']);
        $failed = PostTarget::factory()->forPostAndAccount($post, $failedPage)->create(['status' => TargetStatus::Failed, 'error' => 'Caption too long']);
        $done = PostTarget::factory()->forPostAndAccount($post, $donePage)->create(['status' => TargetStatus::Published]);

        $this->actingAs($post->user)->put(route('posts.update', $post), ['caption' => 'Fixed', 'when' => 'now'])->assertSessionHas('success');

        $this->assertSame('Fixed', $post->fresh()->caption);
        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);
        $this->assertSame(TargetStatus::Pending, $failed->fresh()->status);
        $this->assertNull($failed->fresh()->error);
        $this->assertSame(TargetStatus::Published, $done->fresh()->status);
        Queue::assertPushed(PublishPostTarget::class, fn (PublishPostTarget $job) => $job->target->is($failed));
        Queue::assertPushed(PublishPostTarget::class, 1);
    }

    public function test_only_save_keeps_the_post_status(): void
    {
        $page = SocialAccount::factory()->facebook()->create();
        $post = Post::factory()->for($page->user)->create(['status' => PostStatus::Cancelled]);
        PostTarget::factory()->forPostAndAccount($post, $page)->create();

        $this->actingAs($post->user)->put(route('posts.update', $post), ['caption' => 'Draft text', 'when' => 'keep']);

        $this->assertSame(PostStatus::Cancelled, $post->fresh()->status);
        $this->assertSame('Draft text', $post->fresh()->caption);
        Queue::assertNothingPushed();
    }

    public function test_media_can_be_replaced_with_several_photos(): void
    {
        Storage::disk('public')->put('media/old.jpg', 'old');
        $page = SocialAccount::factory()->facebook()->create();
        $post = Post::factory()->for($page->user)->scheduled()->create(['media_path' => 'media/old.jpg', 'media_type' => Post::MEDIA_PHOTO, 'media_mime' => 'image/jpeg']);
        PostTarget::factory()->forPostAndAccount($post, $page)->create();

        $this->actingAs($post->user)->put(route('posts.update', $post), [
            'caption' => 'New photos',
            'media' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'when' => 'keep',
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('media/old.jpg');
        $this->assertCount(2, $post->fresh()->photoPaths());
    }

    public function test_accounts_can_change_before_anything_is_published(): void
    {
        $first = SocialAccount::factory()->facebook()->create();
        $second = SocialAccount::factory()->for($first->user)->facebook()->create();
        $post = Post::factory()->for($first->user)->scheduled()->create();
        PostTarget::factory()->forPostAndAccount($post, $first)->create();

        $this->actingAs($post->user)->put(route('posts.update', $post), ['caption' => 'x', 'accounts' => [$second->id], 'when' => 'keep']);

        $this->assertSame([$second->id], $post->targets()->pluck('social_account_id')->all());
    }

    public function test_accounts_cannot_change_after_something_was_published(): void
    {
        $first = SocialAccount::factory()->facebook()->create();
        $second = SocialAccount::factory()->for($first->user)->facebook()->create();
        $post = Post::factory()->for($first->user)->create(['status' => PostStatus::PartiallyFailed]);
        PostTarget::factory()->forPostAndAccount($post, $first)->create(['status' => TargetStatus::Published]);

        $this->actingAs($post->user)->put(route('posts.update', $post), ['caption' => 'x', 'accounts' => [$second->id], 'when' => 'keep'])
            ->assertSessionHasErrors('accounts');
    }

    public function test_published_or_busy_posts_cannot_be_edited(): void
    {
        $published = Post::factory()->create(['status' => PostStatus::Published]);
        $busy = Post::factory()->for($published->user)->create(['status' => PostStatus::Publishing]);

        $this->actingAs($published->user)->get(route('posts.edit', $published))->assertRedirect(route('posts.show', $published));
        $this->actingAs($published->user)->put(route('posts.update', $busy), ['caption' => 'x', 'when' => 'keep'])->assertSessionHas('error');

        $this->assertNotSame('x', $busy->fresh()->caption);
    }

    public function test_past_schedule_time_is_rejected(): void
    {
        $post = Post::factory()->scheduled()->create();

        $this->actingAs($post->user)->put(route('posts.update', $post), [
            'caption' => 'x',
            'when' => 'schedule',
            'scheduled_at' => now()->subDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('scheduled_at');
    }

    public function test_other_users_cannot_edit(): void
    {
        $post = Post::factory()->scheduled()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('posts.edit', $post))->assertForbidden();
        $this->actingAs($intruder)->put(route('posts.update', $post), ['caption' => 'x', 'when' => 'keep'])->assertForbidden();
    }

    public function test_edit_buttons_show_only_for_editable_posts(): void
    {
        $user = User::factory()->create();
        $failed = Post::factory()->for($user)->create(['status' => PostStatus::Failed]);
        $published = Post::factory()->for($user)->create(['status' => PostStatus::Published]);

        $this->actingAs($user)->get(route('posts.show', $failed))->assertSee(route('posts.edit', $failed));
        $this->actingAs($user)->get(route('posts.show', $published))->assertDontSee(route('posts.edit', $published));
        $this->actingAs($user)->get(route('posts.index'))->assertSee(route('posts.edit', $failed))->assertDontSee(route('posts.edit', $published));
    }
}
