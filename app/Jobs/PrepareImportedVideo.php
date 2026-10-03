<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Exceptions\DownloadException;
use App\Models\Post;
use App\Models\VideoDownload;
use App\Services\Downloading\VideoDownloader;
use App\Services\Publishing\PostDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads the video of a post created from a YouTube/Facebook link, makes it
 * the post's media, then publishes (or schedules) the post like a normal upload.
 */
class PrepareImportedVideo implements ShouldQueue
{
    use Queueable;

    /**
     * Long videos can take several minutes.
     */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Post $post) {}

    public function handle(VideoDownloader $downloader, PostDispatcher $dispatcher): void
    {
        $post = $this->post;
        $download = $post->videoDownload;

        if ($post->status !== PostStatus::Preparing || $download === null) {
            return;
        }

        $download->update(['status' => VideoDownload::STATUS_DOWNLOADING, 'error' => null]);

        try {
            $file = $downloader->download($download);
        } catch (DownloadException $exception) {
            $this->failed($exception);

            return;
        }

        $download->update([...$file, 'status' => VideoDownload::STATUS_COMPLETED, 'completed_at' => now()]);

        $mediaPath = $this->moveToPublicMedia($file['file_path'], $post);

        $post->update([
            'media_path' => $mediaPath,
            'media_type' => Post::MEDIA_VIDEO,
            'media_mime' => Storage::disk('public')->mimeType($mediaPath) ?: 'video/mp4',
            'title' => $post->title ?: ($file['title'] ? Str::limit($file['title'], 100, '') : null),
            'status' => $post->scheduled_at?->isFuture() ? PostStatus::Scheduled : PostStatus::Publishing,
        ]);

        if ($post->status === PostStatus::Publishing) {
            $dispatcher->dispatch($post);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $post = $this->post->fresh() ?? $this->post;
        $reason = 'Could not get the video from the link: '.($exception?->getMessage() ?: 'the download timed out.');

        $post->videoDownload?->update(['status' => VideoDownload::STATUS_FAILED, 'error' => mb_substr($reason, 0, 2000)]);
        $post->targets()->where('status', TargetStatus::Pending)->update(['status' => TargetStatus::Failed, 'error' => mb_substr($reason, 0, 2000)]);
        $post->update(['status' => PostStatus::Failed]);
    }

    /**
     * Move the file from private downloads to the public media folder, where
     * Instagram and TikTok can fetch it and old media is pruned automatically.
     */
    private function moveToPublicMedia(string $downloadPath, Post $post): string
    {
        $mediaPath = 'media/import-'.$post->id.'-'.Str::random(8).'.'.pathinfo($downloadPath, PATHINFO_EXTENSION);

        Storage::disk('public')->writeStream($mediaPath, Storage::disk('local')->readStream($downloadPath));
        Storage::disk('local')->delete($downloadPath);
        $post->videoDownload->update(['file_path' => null]);

        return $mediaPath;
    }
}
