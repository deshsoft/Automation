<?php

namespace App\Models;

use Database\Factories\LiveStreamTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['social_account_id', 'role', 'status', 'platform_live_id', 'stream_url', 'permalink', 'error'])]
#[Hidden(['stream_url'])]
class LiveStreamTarget extends Model
{
    /** @use HasFactory<LiveStreamTargetFactory> */
    use HasFactory;

    /**
     * Broadcasts the video itself.
     */
    public const ROLE_LIVE = 'live';

    /**
     * Shares the main Page's live video.
     */
    public const ROLE_SHARE = 'share';

    public const STATUS_READY = 'ready';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_SHARED = 'shared';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ENDED = 'ended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stream_url' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<LiveStream, $this>
     */
    public function liveStream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * The "Server" part OBS asks for, e.g. rtmps://live-api-s.facebook.com:443/rtmp/
     */
    public function server(): ?string
    {
        if ($this->stream_url === null) {
            return null;
        }

        return substr($this->stream_url, 0, strrpos($this->stream_url, '/') + 1);
    }

    /**
     * The "Stream key" part OBS asks for.
     */
    public function streamKey(): ?string
    {
        if ($this->stream_url === null) {
            return null;
        }

        return substr($this->stream_url, strrpos($this->stream_url, '/') + 1);
    }
}
