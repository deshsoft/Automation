<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Exceptions\PublishingException;
use App\Models\PostTarget;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Publishes one post to one social account.
 *
 * Asynchronous platforms (Instagram, TikTok) release the job back to the
 * queue until their processing is finished. Network errors and 5xx
 * responses are retried; API rejections fail the target immediately.
 */
class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    /**
     * The post may be deleted while this job waits in the queue; then drop the job.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Large YouTube uploads can take several minutes.
     */
    public int $timeout = 900;

    /**
     * Unexpected (transient) exceptions allowed before giving up.
     */
    public int $maxExceptions = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public PostTarget $target) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(Container $container): void
    {
        $target = $this->target;
        $target->loadMissing(['post', 'socialAccount']);

        if ($target->status->isFinished() || $target->post->status === PostStatus::Cancelled) {
            return;
        }

        if (! $target->socialAccount->is_active) {
            $this->markFailed($target, 'This account is disabled.');

            return;
        }

        $target->update(['status' => TargetStatus::Processing]);

        try {
            $result = $container->make($target->socialAccount->platform->publisher())->publish($target);
        } catch (PublishingException $exception) {
            $this->markFailed($target, $exception->getMessage());

            return;
        }

        if ($result->needsManualPost) {
            $target->update(['status' => TargetStatus::Manual, 'error' => null]);
            $target->post->refreshStatus();

            return;
        }

        if (! $result->isPublished) {
            $this->release($result->checkAgainInSeconds);

            return;
        }

        $target->update([
            'status' => TargetStatus::Published,
            'platform_post_id' => $result->platformPostId,
            'permalink' => $result->permalink,
            'error' => null,
            'published_at' => now(),
        ]);

        $target->post->refreshStatus();
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            $this->target->fresh() ?? $this->target,
            $exception?->getMessage() ?: 'Publishing timed out.',
        );
    }

    private function markFailed(PostTarget $target, string $error): void
    {
        $target->update([
            'status' => TargetStatus::Failed,
            'error' => mb_substr($error, 0, 2000),
        ]);

        $target->post->refreshStatus();
    }
}
