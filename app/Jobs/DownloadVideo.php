<?php

namespace App\Jobs;

use App\Exceptions\DownloadException;
use App\Models\VideoDownload;
use App\Services\Downloading\VideoDownloader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
            $file = $downloader->download($download);
        } catch (DownloadException $exception) {
            $this->markFailed($download, $exception->getMessage());

            return;
        }

        $download->update([
            ...$file,
            'status' => VideoDownload::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
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
