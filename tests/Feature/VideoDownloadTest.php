<?php

namespace Tests\Feature;

use App\Jobs\DownloadVideo;
use App\Models\User;
use App\Models\VideoDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.downloader.binary' => '/opt/bin/yt-dlp',
            'services.downloader.ffmpeg' => '/opt/bin/ffmpeg',
            'services.downloader.deno' => '/opt/bin/deno',
            'services.downloader.cookies' => null,
            'services.downloader.max_filesize_mb' => 2048,
            'services.downloader.keep_days' => 3,
        ]);
    }

    public function test_downloads_page_lists_only_own_downloads(): void
    {
        $user = User::factory()->create();
        $own = VideoDownload::factory()->for($user)->completed('downloads/1/9.mp4')->create(['title' => 'My town hall speech']);
        Storage::disk('local')->put('downloads/1/9.mp4', 'video-bytes');
        VideoDownload::factory()->completed()->create(['title' => 'Someone elses video']);

        $this->actingAs($user)->get(route('downloads.index'))
            ->assertOk()
            ->assertSee('My town hall speech')
            ->assertSee(route('downloads.file', $own))
            ->assertDontSee('Someone elses video');
    }

    public function test_pasting_links_queues_one_download_per_link(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('downloads.store'), [
            'urls' => "https://youtu.be/jNQXAC9IVRw\n https://www.facebook.com/reel/123456789 \nhttps://youtu.be/jNQXAC9IVRw",
            'quality' => '1080',
        ])->assertRedirect(route('downloads.index'));

        $downloads = $user->videoDownloads()->orderBy('id')->get();
        $this->assertCount(2, $downloads);
        $this->assertSame(VideoDownload::SOURCE_YOUTUBE, $downloads[0]->source);
        $this->assertSame(VideoDownload::SOURCE_FACEBOOK, $downloads[1]->source);
        $this->assertSame('1080', $downloads[1]->quality);
        $this->assertSame(VideoDownload::STATUS_QUEUED, $downloads[1]->status);
        Queue::assertPushed(DownloadVideo::class, 2);
    }

    public function test_links_from_other_sites_are_rejected(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post(route('downloads.store'), [
            'urls' => "https://www.youtube.com/watch?v=jNQXAC9IVRw\nhttps://evil-youtube.com/watch?v=1\n--exec=rm",
            'quality' => '720',
        ])->assertSessionHasErrors(['urls.1', 'urls.2']);

        $this->assertDatabaseCount('video_downloads', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_downloads_the_video_and_saves_its_details(): void
    {
        $download = VideoDownload::factory()->create(['url' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw', 'quality' => '720']);
        $savedPath = Storage::disk('local')->path("downloads/{$download->user_id}/{$download->id}.mp4");

        Process::fake(function (PendingProcess $process) use ($savedPath) {
            file_put_contents($savedPath, str_repeat('x', 2048));

            return Process::result(
                output: "[youtube] Extracting URL\n".json_encode(['title' => 'Me at the zoo', 'duration' => 19.4, 'thumbnail' => 'https://i.ytimg.com/vi/x/mq.jpg', 'filepath' => $savedPath]),
            );
        });

        DownloadVideo::dispatchSync($download);

        Process::assertRan(function (PendingProcess $process) use ($download) {
            $command = $process->command;

            return $command[0] === '/opt/bin/yt-dlp'
                && array_slice($command, -2) === ['--', $download->url]
                && in_array('res:720,vcodec:h264,acodec:aac', $command, true)
                && in_array('/opt/bin/ffmpeg', $command, true)
                && in_array('deno:/opt/bin/deno', $command, true);
        });

        $download->refresh();
        $this->assertSame(VideoDownload::STATUS_COMPLETED, $download->status);
        $this->assertSame('Me at the zoo', $download->title);
        $this->assertSame(19, $download->duration);
        $this->assertSame("downloads/{$download->user_id}/{$download->id}.mp4", $download->file_path);
        $this->assertSame(2048, $download->file_size);
        $this->assertNotNull($download->completed_at);
        $this->assertTrue($download->hasFile());
    }

    public function test_audio_only_converts_to_mp3(): void
    {
        $download = VideoDownload::factory()->create(['quality' => 'audio']);

        Process::fake(['*' => Process::result(output: '')]);

        DownloadVideo::dispatchSync($download);

        Process::assertRan(fn (PendingProcess $process) => in_array('--audio-format', $process->command, true)
            && in_array('mp3', $process->command, true));
    }

    public function test_job_marks_download_failed_with_the_yt_dlp_error(): void
    {
        $download = VideoDownload::factory()->create();

        Process::fake(['*' => Process::result(
            errorOutput: "WARNING: something\nERROR: [youtube] jNQXAC9IVRw: Sign in to confirm you're not a bot",
            exitCode: 1,
        )]);

        DownloadVideo::dispatchSync($download);

        $download->refresh();
        $this->assertSame(VideoDownload::STATUS_FAILED, $download->status);
        $this->assertStringStartsWith("[youtube] jNQXAC9IVRw: Sign in to confirm you're not a bot", $download->error);
        $this->assertStringContainsString('YTDLP_COOKIES', $download->error);
    }

    public function test_job_fails_when_nothing_was_saved(): void
    {
        $download = VideoDownload::factory()->create();

        Process::fake(['*' => Process::result(output: '[download] File is larger than max-filesize')]);

        DownloadVideo::dispatchSync($download);

        $download->refresh();
        $this->assertSame(VideoDownload::STATUS_FAILED, $download->status);
        $this->assertStringContainsString('Nothing was downloaded', $download->error);
    }

    public function test_owner_can_save_the_file_but_others_cannot(): void
    {
        $download = VideoDownload::factory()->completed('downloads/1/5.mp4')->create(['title' => 'Rally: Dhaka / 2026']);
        Storage::disk('local')->put('downloads/1/5.mp4', 'video-bytes');

        $this->actingAs($download->user)->get(route('downloads.file', $download))
            ->assertOk()
            ->assertDownload('Rally Dhaka 2026.mp4');

        $this->actingAs(User::factory()->create())->get(route('downloads.file', $download))
            ->assertForbidden();
    }

    public function test_deleting_a_download_removes_its_file(): void
    {
        $download = VideoDownload::factory()->completed('downloads/1/7.mp4')->create();
        Storage::disk('local')->put('downloads/1/7.mp4', 'video-bytes');

        $this->actingAs(User::factory()->create())->delete(route('downloads.destroy', $download))->assertForbidden();

        $this->actingAs($download->user)->delete(route('downloads.destroy', $download))->assertRedirect();

        $this->assertModelMissing($download);
        Storage::disk('local')->assertMissing('downloads/1/7.mp4');
    }

    public function test_failed_download_can_be_retried(): void
    {
        Queue::fake();
        $download = VideoDownload::factory()->failed()->create();

        $this->actingAs($download->user)->post(route('downloads.retry', $download))->assertRedirect();

        $this->assertSame(VideoDownload::STATUS_QUEUED, $download->fresh()->status);
        $this->assertNull($download->fresh()->error);
        Queue::assertPushed(DownloadVideo::class);
    }

    public function test_prune_deletes_old_files_only(): void
    {
        $old = VideoDownload::factory()->completed('downloads/1/old.mp4')->create(['created_at' => now()->subDays(4)]);
        $recent = VideoDownload::factory()->completed('downloads/1/new.mp4')->create();
        Storage::disk('local')->put('downloads/1/old.mp4', 'x');
        Storage::disk('local')->put('downloads/1/new.mp4', 'x');

        $this->artisan('downloads:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('downloads/1/old.mp4');
        Storage::disk('local')->assertExists('downloads/1/new.mp4');
        $this->assertNull($old->fresh()->file_path);
        $this->assertNotNull($recent->fresh()->file_path);
    }
}
