<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\Publishing\PostDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:publish-due')]
#[Description('Queue scheduled posts whose publish time has arrived')]
class PublishDuePosts extends Command
{
    public function handle(PostDispatcher $dispatcher): int
    {
        $started = 0;

        Post::query()
            ->where('status', PostStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (Post $post) use ($dispatcher, &$started) {
                if ($dispatcher->dispatch($post)) {
                    $started++;
                }
            });

        $this->info("Started {$started} scheduled post(s).");

        return self::SUCCESS;
    }
}
