<?php

namespace App\Enums;

use App\Services\Publishing\FacebookPublisher;
use App\Services\Publishing\InstagramPublisher;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\TikTokPublisher;
use App\Services\Publishing\YouTubePublisher;

enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook Page',
            self::Instagram => 'Instagram',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
        };
    }

    /**
     * Whether a post must include a photo or video for this platform.
     */
    public function requiresMedia(): bool
    {
        return $this !== self::Facebook;
    }

    /**
     * Whether this platform accepts photo posts (YouTube only accepts videos).
     */
    public function acceptsPhotos(): bool
    {
        return $this !== self::YouTube;
    }

    /**
     * @return class-string<Publisher>
     */
    public function publisher(): string
    {
        return match ($this) {
            self::Facebook => FacebookPublisher::class,
            self::Instagram => InstagramPublisher::class,
            self::YouTube => YouTubePublisher::class,
            self::TikTok => TikTokPublisher::class,
        };
    }
}
