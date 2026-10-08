<?php

namespace App\Jobs;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\PostTarget;
use App\Services\Publishing\FacebookPublisher;
use App\Services\Publishing\YouTubePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends edited text to a post that is already published (Facebook and YouTube
 * allow this; Instagram and TikTok do not). The result is shown on the post page.
 */
class UpdateLivePost implements ShouldQueue
{
    use Queueable;

    /**
     * Platforms whose published posts can be edited.
     *
     * @var list<Platform>
     */
    public const EDITABLE_PLATFORMS = [Platform::Facebook, Platform::YouTube];

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * The post may be deleted while this job waits in the queue; then drop the job.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public PostTarget $target) {}

    public function handle(FacebookPublisher $facebook, YouTubePublisher $youtube): void
    {
        $target = $this->target->loadMissing(['post', 'socialAccount']);

        try {
            match ($target->socialAccount->platform) {
                Platform::Facebook => $facebook->updateLive($target),
                Platform::YouTube => $youtube->updateLive($target),
                default => throw new PublishingException('This platform does not allow editing after posting.'),
            };
        } catch (PublishingException $exception) {
            $this->remember(false, $exception->getMessage());

            return;
        }

        $this->remember(true, 'Updated with the edited text.');
    }

    public function failed(?Throwable $exception): void
    {
        $this->remember(false, 'The edit could not be sent: '.($exception?->getMessage() ?: 'unknown error'));
    }

    private function remember(bool $ok, string $message): void
    {
        $this->target->rememberState(['live_update' => ['ok' => $ok, 'message' => mb_substr($message, 0, 500), 'at' => now()->toIso8601String()]]);
    }
}
