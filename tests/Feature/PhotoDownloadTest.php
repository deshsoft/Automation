<?php

namespace Tests\Feature;

use App\Jobs\DownloadVideo;
use App\Models\VideoDownload;
use App\Services\Downloading\PhotoFallback;
use App\Services\LinkPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;
use ZipArchive;

class PhotoDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->mock(LinkPreviewer::class, fn (MockInterface $mock) => $mock->shouldReceive('preview')->andReturn([
            'url' => 'https://www.facebook.com/page/posts/1', 'site_name' => 'Facebook', 'title' => 'Page', 'description' => 'Rally in Mirpur', 'image' => null,
        ]));
    }

    public function test_facebook_photo_post_downloads_its_photos_as_a_zip(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: [facebook] 1: No video formats found', exitCode: 1)]);
        $this->photosFound(['media/one.jpg', 'media/two.jpg']);
        $download = $this->facebookDownload();

        DownloadVideo::dispatchSync($download);

        $download->refresh();
        $this->assertSame(VideoDownload::STATUS_COMPLETED, $download->status);
        $this->assertStringEndsWith('.zip', $download->file_path);
        $this->assertSame('Rally in Mirpur', $download->title);
        $this->assertStringEndsWith('.zip', $download->downloadName());

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($download->file_path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('photo-01.jpg', $zip->getNameIndex(0));
        $zip->close();

        Storage::disk('public')->assertMissing(['media/one.jpg', 'media/two.jpg']);
    }

    public function test_photos_option_downloads_a_single_photo_as_jpg_without_trying_video(): void
    {
        Process::fake();
        $this->photosFound(['media/one.jpg']);
        $download = $this->facebookDownload(['quality' => 'photos']);

        DownloadVideo::dispatchSync($download);

        Process::assertNothingRan();
        $this->assertStringEndsWith('.jpg', $download->fresh()->file_path);
        $this->assertSame('photo-bytes', Storage::disk('local')->get($download->fresh()->file_path));
    }

    public function test_failure_shows_both_the_video_and_the_photo_reason(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: [facebook] 1: No video formats found', exitCode: 1)]);
        $this->mock(PhotoFallback::class, function (MockInterface $mock) {
            $mock->lastError = 'no photo found in the link';
            $mock->shouldReceive('importPhotos')->andReturn([]);
        });
        $download = $this->facebookDownload();

        DownloadVideo::dispatchSync($download);

        $this->assertSame(VideoDownload::STATUS_FAILED, $download->fresh()->status);
        $this->assertStringContainsString('Tried the photos instead: no photo found in the link', $download->fresh()->error);
    }

    public function test_youtube_failures_do_not_try_photos(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: [youtube] x: Video unavailable', exitCode: 1)]);
        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldNotReceive('importPhotos'));
        $download = VideoDownload::factory()->create(['url' => 'https://www.youtube.com/watch?v=x', 'source' => VideoDownload::SOURCE_YOUTUBE]);

        DownloadVideo::dispatchSync($download);

        $this->assertSame(VideoDownload::STATUS_FAILED, $download->fresh()->status);
    }

    /**
     * @param  list<string>  $paths
     */
    private function photosFound(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('public')->put($path, 'photo-bytes');
        }

        $this->mock(PhotoFallback::class, fn (MockInterface $mock) => $mock->shouldReceive('importPhotos')->andReturn($paths));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function facebookDownload(array $attributes = []): VideoDownload
    {
        return VideoDownload::factory()->create([
            'url' => 'https://www.facebook.com/share/p/abc/',
            'source' => VideoDownload::SOURCE_FACEBOOK,
            'quality' => 'best',
            ...$attributes,
        ]);
    }
}
