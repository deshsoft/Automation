<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:prune-media {--days=7 : Keep media for this many days after publishing}')]
#[Description('Delete uploaded photos and videos of finished posts to free disk space')]
class PruneOldMedia extends Command
{
    public function handle(): int
    {
        $deleted = 0;

        Post::query()
            ->whereIn('status', [PostStatus::Published, PostStatus::Failed, PostStatus::PartiallyFailed, PostStatus::Cancelled])
            ->where(fn ($query) => $query->whereNotNull('media_path')->orWhereNotNull('thumbnail_path'))
            ->where('updated_at', '<', now()->subDays((int) $this->option('days')))
            ->each(function (Post $post) use (&$deleted) {
                $post->deleteMediaFiles();
                $post->update(['media_path' => null, 'thumbnail_path' => null]);
                $deleted++;
            });

        $this->info("Deleted media of {$deleted} post(s).");

        return self::SUCCESS;
    }
}
