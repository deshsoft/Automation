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
use App\Services\Downloading\PhotoFallback;
use App\Services\Downloading\VideoDownloader;
use App\Services\LinkPreviewer;
use App\Services\Publishing\PostDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class PhotoFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_photo_link_publishes_the_photo_and_a_video_made_for_youtube(): void
    {
        Queue::fake();
        [$post, $youtube, $tiktok] = $this->photoLinkPost();
        $this->noVideoInLink();
        $this->mock(PhotoFallback::class, function (MockInterface $mock) {
            $mock->shouldReceive('importPhoto')->andReturn('media/photo.jpg');
            $mock->shouldReceive('makeVideo')->with('media/photo.jpg', \Mockery::any())->andReturn('media/photo-video.mp4');
        });

        $this->runJob($post);

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(Post::MEDIA_PHOTO, $post->media_type);
        $this->assertSame('media/photo.jpg', $post->media_path);
        $this->assertSame('media/photo-video.mp4', $post->option('youtube_video_path'));
        Queue::assertPushed(PublishPostTarget::class, 2);
    }

    public function test_without_ffmpeg_only_youtube_fails_with_instructions(): void
    {
        Queue::fake();
        [$post, $youtube, $tiktok] = $this->photoLinkPost();
        $this->noVideoInLink();
        $this->mock(PhotoFallback::class, function (MockInterface $mock) {
            $mock->shouldReceive('importPhoto')->andReturn('media/photo.jpg');
            $mock->shouldReceive('makeVideo')->andReturn(null);
        });

        $this->runJob($post);

        $youtubeTarget = $post->targets()->where('social_account_id', $youtube->id)->sole();
        $this->assertSame(TargetStatus::Failed, $youtubeTarget->status);
        $this->assertStringContainsString('ffmpeg', $youtubeTarget->error);
        Queue::assertPushed(PublishPostTarget::class, 1);
    }

    public function test_link_without_video_or_photo_fails_with_the_download_error(): void
    {
        [$post] = $this->photoLinkPost();
        $this->noVideoInLink();
        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldReceive('importPhoto')->andReturn(null));

        $this->runJob($post);

        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('HTTP Error 404', $post->targets()->first()->error);
    }

    public function test_photo_is_taken_from_the_link_preview_and_saved_as_jpg(): void
    {
        $this->app->instance(LinkPreviewer::class, new LinkPreviewer(fn () => ['157.240.1.35']));
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        Http::fake([
            'www.facebook.com/*' => Http::response('<meta property="og:image" content="https://lookaside.fbsbx.com/photo.png">'),
            'lookaside.fbsbx.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $post = Post::factory()->create();

        $path = app(PhotoFallback::class)->importPhoto('https://www.facebook.com/page/posts/123', $post);

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(Storage::disk('public')->get($path))[2]);
    }

    public function test_youtube_uploads_the_video_made_from_the_photo(): void
    {
        Storage::disk('public')->put('media/photo-video.mp4', 'mp4-bytes');
        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example.test/s']),
            'upload.example.test/s' => Http::response(['id' => 'yt-9'], 201),
        ]);
        $channel = SocialAccount::factory()->youtube()->create();
        $post = Post::factory()->for($channel->user)->withPhoto()->create(['options' => ['youtube_video_path' => 'media/photo-video.mp4']]);
        $target = PostTarget::factory()->forPostAndAccount($post, $channel)->create();

        (new PublishPostTarget($target))->withFakeQueueInteractions()->handle(app());

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Upload-Content-Type', 'video/mp4'));
    }

    /**
     * @return array{0: Post, 1: SocialAccount, 2: SocialAccount}
     */
    private function photoLinkPost(): array
    {
        $user = User::factory()->create();
        $youtube = SocialAccount::factory()->for($user)->youtube()->create();
        $tiktok = SocialAccount::factory()->for($user)->tiktok()->create();
        $download = VideoDownload::factory()->for($user)->create(['url' => 'https://www.facebook.com/share/p/abc/', 'status' => VideoDownload::STATUS_QUEUED]);
        $post = Post::factory()->for($user)->create([
            'status' => PostStatus::Preparing,
            'media_type' => Post::MEDIA_VIDEO,
            'video_download_id' => $download->id,
            'options' => ['import_url' => 'https://www.facebook.com/share/p/abc/'],
        ]);
        PostTarget::factory()->forPostAndAccount($post, $youtube)->create();
        PostTarget::factory()->forPostAndAccount($post, $tiktok)->create();

        return [$post, $youtube, $tiktok];
    }

    private function noVideoInLink(): void
    {
        $this->mock(VideoDownloader::class, fn (MockInterface $mock) => $mock->shouldReceive('download')
            ->andThrow(new DownloadException('[facebook] abc: Unable to download webpage: HTTP Error 404: Not Found')));
    }

    private function runJob(Post $post): void
    {
        (new PrepareImportedVideo($post))->handle(app(VideoDownloader::class), app(PostDispatcher::class));
    }
}
