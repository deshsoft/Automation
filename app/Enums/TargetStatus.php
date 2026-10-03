<?php

namespace App\Enums;

enum TargetStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Published || $this === self::Failed;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-700',
            self::Processing => 'bg-amber-100 text-amber-800',
            self::Published => 'bg-green-100 text-green-800',
            self::Failed => 'bg-red-100 text-red-800',
        };
    }
}
