<?php

namespace App\Enums;

enum PostStatus: string
{
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::PartiallyFailed => 'Partially failed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'bg-blue-100 text-blue-800',
            self::Publishing => 'bg-amber-100 text-amber-800',
            self::Published => 'bg-green-100 text-green-800',
            self::PartiallyFailed => 'bg-orange-100 text-orange-800',
            self::Failed => 'bg-red-100 text-red-800',
            self::Cancelled => 'bg-gray-100 text-gray-700',
        };
    }
}
