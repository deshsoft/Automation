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
use App\Services\Downloading\MusicFetcher;
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
        config(['services.facebook.graph_version' => 'v24.0']);
    }

    public function test_photo_link_publishes_all_its_photos(): void
    {
        Queue::fake();
        [$post] = $this->photoLinkPost();
        $this->noVideoInLink();
        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldReceive('importPhotos')->andReturn(['media/a.jpg', 'media/b.jpg']));

        $this->runJob($post);

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(Post::MEDIA_PHOTO, $post->media_type);
        $this->assertSame(['media/a.jpg', 'media/b.jpg'], $post->photoPaths());
        Queue::assertPushed(PublishPostTarget::class, 2);
    }

    public function test_link_without_video_or_photo_fails_with_both_reasons(): void
    {
        [$post] = $this->photoLinkPost();
        $this->noVideoInLink();
        $this->mock(PhotoFallback::class, function (MockInterface $mock) {
            $mock->lastError = 'no photo found in the link';
            $mock->shouldReceive('importPhotos')->andReturn([]);
        });

        $this->runJob($post);

        $error = $post->targets()->first()->error;
        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('HTTP Error 404', $error);
        $this->assertStringContainsString('Tried the photo instead: no photo found', $error);
    }

    public function test_photo_is_taken_from_the_link_preview_and_saved_as_jpg(): void
    {
        $this->app->instance(LinkPreviewer::class, new LinkPreviewer(fn () => ['157.240.1.35']));
        Http::fake([
            'www.facebook.com/*' => Http::response('<meta property="og:image" content="https://lookaside.fbsbx.com/photo.png">'),
            'lookaside.fbsbx.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $paths = app(PhotoFallback::class)->importPhotos('https://www.facebook.com/page/posts/123', Post::factory()->create());

        $this->assertCount(1, $paths);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(Storage::disk('public')->get($paths[0]))[2]);
    }

    public function test_all_photos_are_read_from_a_post_on_a_connected_page(): void
    {
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '555', 'access_token' => 'page-token']);
        Http::fake([
            'graph.facebook.com/v24.0/555_12345678901*' => Http::response(['attachments' => ['data' => [[
                'subattachments' => ['data' => [
                    ['media' => ['image' => ['src' => 'https://scontent.xx.fbcdn.net/one.jpg']]],
                    ['media' => ['image' => ['src' => 'https://scontent.xx.fbcdn.net/two.jpg']]],
                    ['media' => ['image' => ['src' => 'https://scontent.xx.fbcdn.net/three.jpg']]],
                ]],
            ]]]]),
            'scontent.xx.fbcdn.net/*' => Http::response($this->png()),
        ]);

        $paths = app(PhotoFallback::class)->importPhotos('https://www.facebook.com/MyPage/posts/12345678901', Post::factory()->for($page->user)->create());

        $this->assertCount(3, $paths);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '555_12345678901') && $request['access_token'] === 'page-token');
    }

    public function test_youtube_uploads_a_slideshow_of_all_photos(): void
    {
        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldReceive('videoForPost')->once()->andReturnUsing(function () {
            Storage::disk('public')->put('media/slideshow.mp4', 'mp4-bytes');

            return 'media/slideshow.mp4';
        }));
        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example.test/s']),
            'upload.example.test/s' => Http::response(['id' => 'yt-9'], 201),
        ]);
        $channel = SocialAccount::factory()->youtube()->create();
        $post = Post::factory()->for($channel->user)->withPhoto()->create(['options' => ['gallery' => ['media/a.jpg', 'media/b.jpg']]]);
        $target = PostTarget::factory()->forPostAndAccount($post, $channel)->create();

        (new PublishPostTarget($target))->withFakeQueueInteractions()->handle(app());

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertSame('media/slideshow.mp4', $post->fresh()->option('youtube_video_path'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Upload-Content-Type', 'video/mp4'));
    }

    public function test_youtube_shows_why_the_video_could_not_be_made(): void
    {
        $this->mock(PhotoFallback::class, function (MockInterface $mock) {
            $mock->lastError = 'the background music could not be downloaded';
            $mock->shouldReceive('videoForPost')->andReturn(null);
        });
        $channel = SocialAccount::factory()->youtube()->create();
        $post = Post::factory()->for($channel->user)->withPhoto()->create();
        $target = PostTarget::factory()->forPostAndAccount($post, $channel)->create();

        (new PublishPostTarget($target))->withFakeQueueInteractions()->handle(app());

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertStringContainsString('the background music could not be downloaded', $target->fresh()->error);
    }

    public function test_music_from_a_direct_link_is_downloaded_once(): void
    {
        Http::fake(['music.example.com/*' => Http::response('mp3-bytes')]);
        $fetcher = app(MusicFetcher::class);

        $first = $fetcher->fetch('https://music.example.com/song.mp3', 1);
        $second = $fetcher->fetch('https://music.example.com/song.mp3', 1);

        $this->assertSame($first, $second);
        $this->assertStringEndsWith('.mp3', $first);
        $this->assertSame('mp3-bytes', file_get_contents($first));
        Http::assertSentCount(1);
    }

    public function test_missing_ffmpeg_is_reported(): void
    {
        $this->mock(VideoDownloader::class, fn (MockInterface $mock) => $mock->shouldReceive('ffmpeg')->andReturn(null));
        $fallback = app(PhotoFallback::class);

        $this->assertNull($fallback->makeVideo(['media/photo.jpg'], Post::factory()->create()));
        $this->assertStringContainsString('ffmpeg is not installed', $fallback->lastError);
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

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_photos_are_read_from_a_connected_page_even_behind_the_login_wall(): void
    {
        $this->app->instance(LinkPreviewer::class, new LinkPreviewer(fn () => ['157.240.1.35']));
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '61550000000001', 'access_token' => 'page-token']);
        Http::fake([
            'www.facebook.com/share/p/xyz*' => Http::response('', 302, [
                'Location' => 'https://www.facebook.com/login/?next=https%3A%2F%2Fwww.facebook.com%2Fstory.php%3Fstory_fbid%3D122113169931477750%26id%3D61550000000001',
            ]),
            'www.facebook.com/login/*' => Http::response('<meta property="og:title" content="Log in to Facebook">'),
            'graph.facebook.com/v24.0/61550000000001_122113169931477750*' => Http::response(['attachments' => ['data' => [[
                'subattachments' => ['data' => [
                    ['media' => ['image' => ['src' => 'https://scontent.xx.fbcdn.net/one.jpg']]],
                    ['media' => ['image' => ['src' => 'https://scontent.xx.fbcdn.net/two.jpg']]],
                ]],
            ]]]]),
            'scontent.xx.fbcdn.net/*' => Http::response($this->png()),
        ]);

        $paths = app(PhotoFallback::class)->importPhotos('https://www.facebook.com/share/p/xyz/', Post::factory()->for($page->user)->create());

        $this->assertCount(2, $paths);
    }

    public function test_login_wall_gives_a_clear_explanation(): void
    {
        [$post] = $this->photoLinkPost();
        $this->mock(VideoDownloader::class, fn (MockInterface $mock) => $mock->shouldReceive('download')
            ->andThrow(new DownloadException('Unsupported URL: https://www.facebook.com/login/?next=https%3A%2F%2Fwww.facebook.com%2Fstory.php')));
        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldReceive('importPhotos')->andReturn([]));

        $this->runJob($post);

        $error = $post->targets()->first()->error;
        $this->assertStringContainsString('Facebook asked for a login to show this post', $error);
        $this->assertStringContainsString('connect it in Accounts', $error);
    }
}
