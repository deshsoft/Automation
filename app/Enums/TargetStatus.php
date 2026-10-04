<?php

namespace App\Enums;

enum TargetStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';

    /**
     * Prepared by the app, but the user posts it by hand (e.g. YouTube photo
     * posts, which have no API). Becomes Published when marked as posted.
     */
    case Manual = 'manual';

    public function isFinished(): bool
    {
        return in_array($this, [self::Published, self::Failed, self::Manual], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ready to post',
            default => ucfirst($this->value),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-700',
            self::Processing => 'bg-amber-100 text-amber-800',
            self::Published => 'bg-green-100 text-green-800',
            self::Failed => 'bg-red-100 text-red-800',
            self::Manual => 'bg-sky-100 text-sky-800',
        };
    }
}
