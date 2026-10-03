<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\TargetStatus;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'social_account_id',
    'status',
    'platform_post_id',
    'permalink',
    'state',
    'error',
    'published_at',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'state' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Whether this Facebook Page shares the main Page's post instead of posting its own copy.
     */
    public function isShare(): bool
    {
        return $this->post->usesShareMode()
            && $this->socialAccount->platform === Platform::Facebook
            && $this->social_account_id !== $this->post->share_from_account_id;
    }

    /**
     * Merge values into the remembered state for multi-step uploads.
     *
     * @param  array<string, mixed>  $values
     */
    public function rememberState(array $values): void
    {
        $this->update(['state' => array_merge($this->state ?? [], $values)]);
    }
}
