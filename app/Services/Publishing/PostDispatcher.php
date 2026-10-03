<?php

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;

class PostDispatcher
{
    /**
     * Queue one job per account. Jobs are spaced out by the post's stagger
     * gap so many pages do not publish the exact same thing at the same second.
     * In share mode the main Page is queued first.
     *
     * Returns false when another process already started this post.
     */
    public function dispatch(Post $post): bool
    {
        if ($post->status === PostStatus::Scheduled) {
            // Scheduled -> Publishing always changes the row, so the affected
            // row count reliably tells whether this process won the claim.
            $claimed = Post::query()
                ->whereKey($post->id)
                ->where('status', PostStatus::Scheduled)
                ->update(['status' => PostStatus::Publishing]);

            if ($claimed === 0) {
                return false;
            }

            $post->status = PostStatus::Publishing;
        }

        if ($post->status !== PostStatus::Publishing) {
            return false;
        }

        $post->targets()
            ->where('status', TargetStatus::Pending)
            ->orderBy('id')
            ->get()
            ->sortBy(fn ($target) => $target->social_account_id === $post->share_from_account_id ? 0 : 1)
            ->values()
            ->each(function ($target, int $index) use ($post) {
                PublishPostTarget::dispatch($target)->delay(now()->addSeconds($index * $post->stagger_seconds));
            });

        return true;
    }

    /**
     * Put failed targets back in the queue.
     */
    public function retryFailed(Post $post): int
    {
        $failedTargets = $post->targets()->where('status', TargetStatus::Failed)->get();

        foreach ($failedTargets as $target) {
            $target->update(['status' => TargetStatus::Pending, 'error' => null, 'state' => null]);
        }

        if ($failedTargets->isNotEmpty()) {
            $post->update(['status' => PostStatus::Publishing]);
            $this->dispatch($post);
        }

        return $failedTargets->count();
    }
}
