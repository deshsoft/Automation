<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Downloading\PhotoFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;
use ZipArchive;

class YouTubeFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_youtube_format_is_saved_with_the_post(): void
    {
        Queue::fake();
        $channel = SocialAccount::factory()->youtube()->create();

        $this->actingAs($channel->user)->post(route('posts.store'), [
            'accounts' => [$channel->id],
            'media' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'youtube_format' => 'post',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertSame('post', Post::sole()->option('youtube.format'));
    }

    public function test_unknown_format_is_rejected(): void
    {
        $channel = SocialAccount::factory()->youtube()->create();

        $this->actingAs($channel->user)->post(route('posts.store'), [
            'accounts' => [$channel->id],
            'media' => UploadedFile::fake()->image('a.jpg'),
            'youtube_format' => 'story',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('youtube_format');
    }

    public function test_photo_post_format_is_prepared_for_posting_by_hand(): void
    {
        Http::fake();
        [$post, $target] = $this->youtubePhotoPost('post');

        (new PublishPostTarget($target))->withFakeQueueInteractions()->handle(app());

        Http::assertNothingSent();
        $this->assertSame(TargetStatus::Manual, $target->fresh()->status);
        $this->assertSame(PostStatus::Published, $post->fresh()->status);

        $this->actingAs($post->user)->get(route('posts.show', $post))
            ->assertSee('Ready to post')
            ->assertSee('Download photos (2)')
            ->assertSee('https://www.youtube.com/channel/'.$target->socialAccount->platform_account_id.'/posts')
            ->assertSee('Mark as posted');
    }

    public function test_regular_video_format_makes_a_horizontal_slideshow(): void
    {
        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldReceive('videoForPost')->once()->andReturnUsing(function (Post $post) {
            $this->assertSame('video', $post->option('youtube.format'));
            Storage::disk('public')->put('media/wide.mp4', 'mp4');

            return 'media/wide.mp4';
        }));
        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example.test/s']),
            'upload.example.test/s' => Http::response(['id' => 'yt-1'], 201),
        ]);
        [, $target] = $this->youtubePhotoPost('video');

        (new PublishPostTarget($target))->withFakeQueueInteractions()->handle(app());

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Upload-Content-Type', 'video/mp4'));
    }

    public function test_photos_download_as_one_zip(): void
    {
        [$post] = $this->youtubePhotoPost('post');

        $response = $this->actingAs($post->user)->get(route('posts.photos', $post))->assertOk();

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('photo-01.jpg', $zip->getNameIndex(0));
        $zip->close();
    }

    public function test_marking_as_posted_publishes_the_item(): void
    {
        [$post, $target] = $this->youtubePhotoPost('post');
        $target->update(['status' => TargetStatus::Manual]);

        $this->actingAs($post->user)->post(route('post-targets.mark-posted', $target), ['permalink' => 'https://www.youtube.com/post/Ugkx123'])
            ->assertSessionHas('success');

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertSame('https://www.youtube.com/post/Ugkx123', $target->fresh()->permalink);
    }

    public function test_only_ready_to_post_items_can_be_marked(): void
    {
        [$post, $target] = $this->youtubePhotoPost('post');

        $this->actingAs($post->user)->post(route('post-targets.mark-posted', $target))->assertSessionHas('error');

        $this->assertSame(TargetStatus::Pending, $target->fresh()->status);
    }

    public function test_other_users_cannot_download_or_mark(): void
    {
        [$post, $target] = $this->youtubePhotoPost('post');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('posts.photos', $post))->assertForbidden();
        $this->actingAs($intruder)->post(route('post-targets.mark-posted', $target))->assertForbidden();
    }

    /**
     * @return array{0: Post, 1: PostTarget}
     */
    private function youtubePhotoPost(string $format): array
    {
        Storage::disk('public')->put('media/a.jpg', 'a');
        Storage::disk('public')->put('media/b.jpg', 'b');
        $channel = SocialAccount::factory()->youtube()->create(['platform_account_id' => 'UCchannel123']);
        $post = Post::factory()->for($channel->user)->withPhoto()->create([
            'media_path' => 'media/a.jpg',
            'options' => ['gallery' => ['media/a.jpg', 'media/b.jpg'], 'youtube' => ['format' => $format]],
        ]);

        return [$post, PostTarget::factory()->forPostAndAccount($post, $channel)->create()];
    }

    public function test_waiting_photo_posts_can_switch_to_automatic_shorts(): void
    {
        Queue::fake();
        [$post, $target] = $this->youtubePhotoPost('post');
        $target->update(['status' => TargetStatus::Manual]);
        $post->update(['status' => PostStatus::Published]);

        $this->actingAs($post->user)->get(route('posts.show', $post))->assertSee('Upload as Shorts');

        $this->actingAs($post->user)->post(route('posts.youtube-video', $post), ['format' => 'shorts'])->assertSessionHas('success');

        $this->assertSame('shorts', $post->fresh()->option('youtube.format'));
        $this->assertSame(TargetStatus::Pending, $target->fresh()->status);
        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);
        Queue::assertPushed(PublishPostTarget::class, fn (PublishPostTarget $job) => $job->target->is($target));
    }

    public function test_switching_needs_waiting_items_and_a_valid_format(): void
    {
        [$post] = $this->youtubePhotoPost('post');

        $this->actingAs($post->user)->post(route('posts.youtube-video', $post), ['format' => 'shorts'])->assertSessionHas('error');
        $this->actingAs($post->user)->post(route('posts.youtube-video', $post), ['format' => 'post'])->assertSessionHasErrors('format');
        $this->actingAs(User::factory()->create())->post(route('posts.youtube-video', $post), ['format' => 'shorts'])->assertForbidden();
    }
}
