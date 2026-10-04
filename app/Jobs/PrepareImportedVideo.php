<?php

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Exceptions\DownloadException;
use App\Models\Post;
use App\Models\VideoDownload;
use App\Services\Downloading\PhotoFallback;
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
            // Photo posts have no video: publish their photo instead.
            if (! $this->usePhotoInstead($post, $dispatcher)) {
                $reason = app(PhotoFallback::class)->lastError;
                $this->failed(new DownloadException($exception->getMessage().($reason ? ' Tried the photo instead: '.$reason : '')));
            }

            return;
        }

        $download->update([...$file, 'status' => VideoDownload::STATUS_COMPLETED, 'completed_at' => now()]);

        $mediaPath = $this->moveToPublicMedia($file['file_path'], $post);

        $post->update([
            'media_path' => $mediaPath,
            'media_type' => Post::MEDIA_VIDEO,
            'media_mime' => Storage::disk('public')->mimeType($mediaPath) ?: 'video/mp4',
            'title' => $post->title ?: (blank($post->caption) ? $this->cleanTitle($file['title']) : null),
            'status' => $post->scheduled_at?->isFuture() ? PostStatus::Scheduled : PostStatus::Publishing,
        ]);

        if ($post->status === PostStatus::Publishing) {
            $dispatcher->dispatch($post);
        }
    }

    /**
     * Use the link's photo when there is no video. YouTube gets a short video
     * made from the photo; the other platforms post the photo itself.
     */
    private function usePhotoInstead(Post $post, PostDispatcher $dispatcher): bool
    {
        $fallback = app(PhotoFallback::class);
        $photoPath = $fallback->importPhoto((string) $post->videoDownload->url, $post);

        if ($photoPath === null) {
            return false;
        }

        $post->videoDownload->update(['status' => VideoDownload::STATUS_FAILED, 'error' => 'No video in this link; its photo was used instead.']);

        $options = $post->options ?? [];
        $youtubeTargets = $post->targets()
            ->where('status', TargetStatus::Pending)
            ->whereHas('socialAccount', fn ($query) => $query->where('platform', Platform::YouTube));

        if ($youtubeTargets->exists()) {
            $videoPath = $fallback->makeVideo($photoPath, $post);

            if ($videoPath !== null) {
                $options['youtube_video_path'] = $videoPath;
            } else {
                $youtubeTargets->update([
                    'status' => TargetStatus::Failed,
                    'error' => mb_substr('This link has a photo, not a video, and the video for YouTube could not be made: '.$fallback->lastError, 0, 2000),
                ]);
            }
        }

        $post->update([
            'media_path' => $photoPath,
            'media_type' => Post::MEDIA_PHOTO,
            'media_mime' => 'image/jpeg',
            'options' => $options,
            'status' => $post->scheduled_at?->isFuture() ? PostStatus::Scheduled : PostStatus::Publishing,
        ]);

        if (! $post->targets()->where('status', TargetStatus::Pending)->exists()) {
            $post->refreshStatus();
        } elseif ($post->status === PostStatus::Publishing) {
            $dispatcher->dispatch($post);
        }

        return true;
    }

    /**
     * Fail the accounts that needed the video. Facebook Pages that share the
     * link do not need it, so they are still published.
     */
    public function failed(?Throwable $exception): void
    {
        $post = $this->post->fresh() ?? $this->post;
        $reason = mb_substr('Could not get the video from the link: '.($exception?->getMessage() ?: 'the download timed out.').$this->firewallHint($exception?->getMessage()), 0, 2000);

        $post->videoDownload?->update(['status' => VideoDownload::STATUS_FAILED, 'error' => $reason]);

        $needVideo = $post->targets()
            ->where('status', TargetStatus::Pending)
            ->when(filled($post->option('link')), fn ($query) => $query->whereHas(
                'socialAccount',
                fn ($query) => $query->where('platform', '!=', Platform::Facebook),
            ));
        $needVideo->update(['status' => TargetStatus::Failed, 'error' => $reason]);

        if (! $post->targets()->where('status', TargetStatus::Pending)->exists()) {
            $post->update(['status' => PostStatus::Failed]);
            $post->refreshStatus();

            return;
        }

        $post->update(['status' => $post->scheduled_at?->isFuture() ? PostStatus::Scheduled : PostStatus::Publishing]);

        if ($post->status === PostStatus::Publishing) {
            app(PostDispatcher::class)->dispatch($post);
        }
    }

    /**
     * Timeouts to Facebook's or YouTube's video servers mean the hosting
     * firewall (cPanel → Outgoing Connections) blocks them.
     */
    private function firewallHint(?string $message): string
    {
        if ($message === null || ! str_contains($message, 'timed out')) {
            return '';
        }

        return match (true) {
            str_contains($message, 'fbcdn.net') => ' → The hosting firewall blocks Facebook\'s video servers. In cPanel → Outgoing Connections allow all of fbcdn.net (see System page).',
            str_contains($message, 'googlevideo.com') => ' → The hosting firewall blocks YouTube\'s video servers. In cPanel → Outgoing Connections allow all of googlevideo.com (see System page).',
            default => '',
        };
    }

    /**
     * Facebook titles start with counters such as "11 reactions | " or "2.3K views · ".
     */
    private function cleanTitle(?string $title): ?string
    {
        if (blank($title)) {
            return null;
        }

        $title = preg_replace('/^(?:[\d.,]+\s*[KkMm]?\s+(?:reactions?|views?|comments?|shares?)\s*[|·]\s*)+/u', '', $title);

        return Str::limit(trim((string) $title), 100, '') ?: null;
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
