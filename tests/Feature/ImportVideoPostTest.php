<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Exceptions\DownloadException;
use App\Jobs\PrepareImportedVideo;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VideoDownload;
use App\Services\Downloading\VideoDownloader;
use App\Services\Publishing\PostDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class ImportVideoPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_post_from_a_youtube_link_starts_by_downloading_the_video(): void
    {
        Queue::fake();
        $channel = SocialAccount::factory()->youtube()->create();
        $tiktok = SocialAccount::factory()->for($channel->user)->tiktok()->create();

        $this->actingAs($channel->user)->post(route('posts.store'), [
            'accounts' => [$channel->id, $tiktok->id],
            'caption' => 'Speech at Mirpur',
            'import_url' => 'https://www.youtube.com/watch?v=abc123',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $post = Post::sole();
        $this->assertSame(PostStatus::Preparing, $post->status);
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $post->videoDownload->url);
        $this->assertSame(VideoDownload::SOURCE_YOUTUBE, $post->videoDownload->source);

        Queue::assertPushed(PrepareImportedVideo::class, fn (PrepareImportedVideo $job) => $job->post->is($post));
        Queue::assertNotPushed(PublishPostTarget::class);
    }

    public function test_only_youtube_and_facebook_links_can_be_imported(): void
    {
        $channel = SocialAccount::factory()->youtube()->create();

        $this->actingAs($channel->user)->post(route('posts.store'), [
            'accounts' => [$channel->id],
            'import_url' => 'https://vimeo.com/123',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('import_url');
    }

    public function test_import_cannot_be_combined_with_an_uploaded_file(): void
    {
        $page = SocialAccount::factory()->facebook()->create();

        $this->actingAs($page->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'import_url' => 'https://www.facebook.com/page/videos/123',
            'media' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('import_url');
    }

    public function test_downloaded_video_becomes_the_post_media_and_is_published(): void
    {
        Queue::fake();
        $post = $this->preparingPost();
        Storage::disk('local')->put('downloads/'.$post->user_id.'/'.$post->video_download_id.'.mp4', 'video-bytes');
        $this->mock(VideoDownloader::class, fn (MockInterface $mock) => $mock->shouldReceive('download')->once()->andReturn([
            'title' => 'Mirpur speech full video',
            'thumbnail_url' => null,
            'duration' => 120,
            'file_path' => 'downloads/'.$post->user_id.'/'.$post->video_download_id.'.mp4',
            'file_size' => 11,
        ]));

        (new PrepareImportedVideo($post))->handle(app(VideoDownloader::class), app(PostDispatcher::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(Post::MEDIA_VIDEO, $post->media_type);
        $this->assertSame('Mirpur speech full video', $post->title);
        Storage::disk('public')->assertExists($post->media_path);
        $this->assertSame('video-bytes', Storage::disk('public')->get($post->media_path));
        Storage::disk('local')->assertMissing('downloads/'.$post->user_id.'/'.$post->video_download_id.'.mp4');
        Queue::assertPushed(PublishPostTarget::class, 1);
    }

    public function test_scheduled_import_waits_for_its_time_after_downloading(): void
    {
        Queue::fake();
        $post = $this->preparingPost(['scheduled_at' => now()->addDay()]);
        Storage::disk('local')->put('downloads/x.mp4', 'video-bytes');
        $this->mock(VideoDownloader::class, fn (MockInterface $mock) => $mock->shouldReceive('download')->andReturn([
            'title' => null, 'thumbnail_url' => null, 'duration' => 10, 'file_path' => 'downloads/x.mp4', 'file_size' => 11,
        ]));

        (new PrepareImportedVideo($post))->handle(app(VideoDownloader::class), app(PostDispatcher::class));

        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
        Queue::assertNotPushed(PublishPostTarget::class);
    }

    public function test_failed_download_fails_the_post_with_the_reason(): void
    {
        $post = $this->preparingPost();
        $this->mock(VideoDownloader::class, fn (MockInterface $mock) => $mock->shouldReceive('download')
            ->andThrow(new DownloadException('This video is private.')));

        (new PrepareImportedVideo($post))->handle(app(VideoDownloader::class), app(PostDispatcher::class));

        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $target = $post->targets()->sole();
        $this->assertSame(TargetStatus::Failed, $target->status);
        $this->assertStringContainsString('This video is private.', $target->error);
    }

    public function test_downloading_post_page_refreshes_and_cannot_be_deleted(): void
    {
        $post = $this->preparingPost();

        $this->actingAs($post->user)->get(route('posts.show', $post))
            ->assertSee('Downloading the video')
            ->assertSee('http-equiv="refresh"', false);

        $this->actingAs($post->user)->delete(route('posts.destroy', $post))->assertSessionHas('error');
        $this->assertModelExists($post);
    }

    public function test_imported_downloads_are_not_listed_on_the_download_page(): void
    {
        $post = $this->preparingPost();
        VideoDownload::factory()->for($post->user)->create(['title' => 'My own manual download']);
        $post->videoDownload->update(['title' => 'Imported for a post']);

        $this->actingAs($post->user)->get(route('downloads.index'))
            ->assertSee('My own manual download')
            ->assertDontSee('Imported for a post');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function preparingPost(array $attributes = []): Post
    {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->youtube()->create();
        $download = VideoDownload::factory()->for($user)->create([
            'url' => 'https://www.youtube.com/watch?v=abc123',
            'status' => VideoDownload::STATUS_QUEUED,
        ]);
        $post = Post::factory()->for($user)->create([
            'status' => PostStatus::Preparing,
            'media_type' => Post::MEDIA_VIDEO,
            'video_download_id' => $download->id,
            'options' => ['import_url' => 'https://www.youtube.com/watch?v=abc123'],
            ...$attributes,
        ]);
        PostTarget::factory()->forPostAndAccount($post, $account)->create();

        return $post;
    }
}
