<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'title',
    'caption',
    'media_path',
    'media_type',
    'media_mime',
    'thumbnail_path',
    'options',
    'share_from_account_id',
    'video_download_id',
    'stagger_seconds',
    'status',
    'scheduled_at',
    'published_at',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    public const MEDIA_PHOTO = 'photo';

    public const MEDIA_VIDEO = 'video';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'options' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'stagger_seconds' => 'integer',
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
     * @return HasMany<PostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * The Facebook Page that posts first when the other Pages share its post.
     *
     * @return BelongsTo<SocialAccount, $this>
     */
    public function shareFromAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'share_from_account_id');
    }

    /**
     * The download that supplies this post's video when it was imported from a link.
     *
     * @return BelongsTo<VideoDownload, $this>
     */
    public function videoDownload(): BelongsTo
    {
        return $this->belongsTo(VideoDownload::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [PostStatus::Preparing, PostStatus::Publishing], true);
    }

    public function usesShareMode(): bool
    {
        return $this->share_from_account_id !== null;
    }

    /**
     * The caption for one platform: its custom caption, or the main caption.
     */
    public function captionFor(Platform $platform): string
    {
        $custom = $this->option('captions.'.$platform->value);

        return filled($custom) ? (string) $custom : (string) $this->caption;
    }

    /**
     * Read a value from the per-platform options, e.g. option('youtube.tags', []).
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }

    public function hasMedia(): bool
    {
        return $this->media_path !== null;
    }

    public function isVideo(): bool
    {
        return $this->media_type === self::MEDIA_VIDEO;
    }

    public function isPhoto(): bool
    {
        return $this->media_type === self::MEDIA_PHOTO;
    }

    public function hasThumbnail(): bool
    {
        return $this->isVideo() && $this->thumbnail_path !== null;
    }

    /**
     * Public URL of the video cover image (Instagram downloads it from here).
     */
    public function thumbnailUrl(): ?string
    {
        return $this->hasThumbnail() ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function thumbnailLocalPath(): ?string
    {
        return $this->hasThumbnail() ? Storage::disk('public')->path($this->thumbnail_path) : null;
    }

    /**
     * Delete the uploaded photo/video and the video cover image.
     */
    public function deleteMediaFiles(): void
    {
        Storage::disk('public')->delete(array_filter([$this->media_path, $this->thumbnail_path]));
    }

    /**
     * Public URL that Meta and TikTok servers download the media from.
     */
    public function mediaUrl(): ?string
    {
        return $this->media_path ? Storage::disk('public')->url($this->media_path) : null;
    }

    /**
     * Absolute local path, used for chunked uploads (YouTube).
     */
    public function mediaLocalPath(): ?string
    {
        return $this->media_path ? Storage::disk('public')->path($this->media_path) : null;
    }

    /**
     * YouTube needs a title; fall back to the first line of the caption.
     */
    public function resolvedTitle(): string
    {
        $title = $this->title ?: strtok((string) $this->caption, "\n");

        $title = trim(str_replace(['<', '>'], '', (string) $title));

        return mb_substr($title !== '' ? $title : 'New video', 0, 100);
    }

    /**
     * Recalculate the overall status from the status of every target.
     */
    public function refreshStatus(): void
    {
        $statuses = $this->targets()->pluck('status')->map(fn ($status) => $status instanceof TargetStatus ? $status : TargetStatus::from($status));

        if ($statuses->isEmpty() || $statuses->contains(fn (TargetStatus $status) => ! $status->isFinished())) {
            return;
        }

        $publishedCount = $statuses->filter(fn (TargetStatus $status) => $status === TargetStatus::Published)->count();

        $this->update([
            'status' => match (true) {
                $publishedCount === $statuses->count() => PostStatus::Published,
                $publishedCount === 0 => PostStatus::Failed,
                default => PostStatus::PartiallyFailed,
            },
            'published_at' => $publishedCount > 0 ? now() : null,
        ]);
    }
}
