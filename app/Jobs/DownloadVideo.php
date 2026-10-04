<?php

namespace App\Jobs;

use App\Exceptions\DownloadException;
use App\Models\Post;
use App\Models\VideoDownload;
use App\Services\Downloading\PhotoFallback;
use App\Services\Downloading\VideoDownloader;
use App\Services\LinkPreviewer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Downloads one YouTube or Facebook video to storage/app/private/downloads.
 */
class DownloadVideo implements ShouldQueue
{
    use Queueable;

    /**
     * Long videos can take several minutes.
     */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public VideoDownload $download) {}

    public function handle(VideoDownloader $downloader): void
    {
        $download = $this->download;

        if ($download->isFinished()) {
            return;
        }

        $download->update(['status' => VideoDownload::STATUS_DOWNLOADING, 'error' => null]);

        try {
            $file = $download->quality === 'photos'
                ? $this->downloadPhotos($download)
                : $downloader->download($download);
        } catch (DownloadException $exception) {
            // A Facebook photo post has no video: save its photos instead.
            if ($download->quality !== 'photos' && $download->source === VideoDownload::SOURCE_FACEBOOK) {
                try {
                    $file = $this->downloadPhotos($download);
                } catch (DownloadException $photoException) {
                    $this->markFailed($download, $exception->getMessage().' Tried the photos instead: '.$photoException->getMessage());

                    return;
                }
            } else {
                $this->markFailed($download, $exception->getMessage());

                return;
            }
        }

        $download->update([
            ...$file,
            'status' => VideoDownload::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Save the photos of a Facebook post: one JPG, or a ZIP of all of them.
     *
     * @return array{title: ?string, thumbnail_url: ?string, duration: ?int, file_path: string, file_size: int}
     *
     * @throws DownloadException
     */
    private function downloadPhotos(VideoDownload $download): array
    {
        // Not saved: PhotoFallback names files after a post and reads its owner's Pages.
        $post = new Post(['caption' => null]);
        $post->id = 0;
        $post->user_id = $download->user_id;

        $fallback = app(PhotoFallback::class);
        $photos = $fallback->importPhotos($download->url, $post);

        if ($photos === []) {
            throw new DownloadException($fallback->lastError ?? 'no photos found in this link.');
        }

        $directory = 'downloads/'.$download->user_id;
        Storage::disk('local')->makeDirectory($directory);

        if (count($photos) === 1) {
            $filePath = $directory.'/'.$download->id.'.jpg';
            Storage::disk('local')->writeStream($filePath, Storage::disk('public')->readStream($photos[0]));
        } else {
            $filePath = $directory.'/'.$download->id.'.zip';
            $zip = new ZipArchive;
            $zip->open(Storage::disk('local')->path($filePath), ZipArchive::CREATE | ZipArchive::OVERWRITE);

            foreach ($photos as $index => $photo) {
                $zip->addFromString(sprintf('photo-%02d.jpg', $index + 1), (string) Storage::disk('public')->get($photo));
            }

            $zip->close();
        }

        Storage::disk('public')->delete($photos);
        $preview = app(LinkPreviewer::class)->preview($download->url);

        return [
            'title' => Str::limit((string) ($preview['description'] ?? $preview['title'] ?? (count($photos).' photos')), 250, '') ?: count($photos).' photos',
            'thumbnail_url' => $preview['image'] ?? null,
            'duration' => null,
            'file_path' => $filePath,
            'file_size' => (int) Storage::disk('local')->size($filePath),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            $this->download->fresh() ?? $this->download,
            $exception?->getMessage() ?: 'The download timed out.',
        );
    }

    private function markFailed(VideoDownload $download, string $error): void
    {
        $download->update([
            'status' => VideoDownload::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
        ]);
    }
}
