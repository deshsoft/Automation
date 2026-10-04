<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:prune {--days= : Delete finished posts older than this many days (default: the Settings page; 0 = never)}')]
#[Description('Delete old finished posts and their files to keep the dashboard and disk small')]
class PruneOldPosts extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? Setting::integer('posts_keep_days'));

        if ($days <= 0) {
            $this->info('Automatic post deletion is off.');

            return self::SUCCESS;
        }

        $deleted = 0;

        Post::query()
            ->whereIn('status', [PostStatus::Published, PostStatus::Failed, PostStatus::PartiallyFailed, PostStatus::Cancelled])
            ->where('updated_at', '<', now()->subDays($days))
            ->each(function (Post $post) use (&$deleted) {
                $post->deleteMediaFiles();
                $post->delete();
                $deleted++;
            });

        $this->info("Deleted {$deleted} old post(s).");

        return self::SUCCESS;
    }
}
