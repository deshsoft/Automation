<?php

namespace App\Console\Commands;

use App\Models\VideoDownload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('downloads:prune {--days= : Keep downloaded videos for this many days (default: DOWNLOAD_KEEP_DAYS)}')]
#[Description('Delete downloaded videos older than a few days to free disk space')]
class PruneOldDownloads extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('services.downloader.keep_days'));
        $deleted = 0;

        VideoDownload::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', now()->subDays($days))
            ->each(function (VideoDownload $download) use (&$deleted) {
                $download->deleteFile();
                $download->update(['file_path' => null]);
                $deleted++;
            });

        $this->info("Deleted {$deleted} downloaded video(s).");

        return self::SUCCESS;
    }
}
