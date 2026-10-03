<?php

namespace App\Services\Publishing;

/**
 * The outcome of a single publishing attempt.
 *
 * Instagram and TikTok process media asynchronously, so an attempt can end
 * as "pending" and must be checked again after a delay.
 */
final readonly class PublishResult
{
    private function __construct(
        public bool $isPublished,
        public ?string $platformPostId,
        public ?string $permalink,
        public int $checkAgainInSeconds,
    ) {}

    public static function published(?string $platformPostId, ?string $permalink = null): self
    {
        return new self(true, $platformPostId, $permalink, 0);
    }

    public static function pending(int $checkAgainInSeconds = 30): self
    {
        return new self(false, null, null, $checkAgainInSeconds);
    }
}
