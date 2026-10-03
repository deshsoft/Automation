<?php

namespace App\Models;

use Database\Factories\LiveStreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'share_message', 'status', 'ended_at'])]
class LiveStream extends Model
{
    /** @use HasFactory<LiveStreamFactory> */
    use HasFactory;

    public const STATUS_LIVE = 'live';

    public const STATUS_ENDED = 'ended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LiveStreamTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(LiveStreamTarget::class);
    }

    /**
     * The Page that broadcasts when the other Pages only share the live.
     */
    public function mainTarget(): ?LiveStreamTarget
    {
        return $this->targets->firstWhere('role', LiveStreamTarget::ROLE_LIVE);
    }

    public function usesShareMode(): bool
    {
        return $this->targets->contains('role', LiveStreamTarget::ROLE_SHARE);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }
}
