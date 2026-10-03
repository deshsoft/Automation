<?php

namespace App\Models;

use Database\Factories\VideoDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['url', 'source', 'quality', 'status', 'title', 'thumbnail_url', 'duration', 'file_path', 'file_size', 'error', 'completed_at'])]
class VideoDownload extends Model
{
    /** @use HasFactory<VideoDownloadFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_YOUTUBE = 'youtube';

    public const SOURCE_FACEBOOK = 'facebook';

    /**
     * Quality choices shown on the form, keyed by the stored value.
     *
     * @var array<string, string>
     */
    public const QUALITIES = [
        'best' => 'Best available',
        '1080' => '1080p (Full HD)',
        '720' => '720p (HD)',
        '480' => '480p (small file)',
        'audio' => 'Audio only (MP3)',
    ];

    /**
     * Hosts accepted for each source. Sub-domains (www., m., web.) are allowed too.
     *
     * @var array<string, list<string>>
     */
    public const HOSTS = [
        self::SOURCE_YOUTUBE => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
        self::SOURCE_FACEBOOK => ['facebook.com', 'fb.watch', 'fb.com'],
    ];

    /**
     * Which source a link belongs to, or null when it is not a YouTube or Facebook link.
     */
    public static function sourceOf(string $url): ?string
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::HOSTS as $source => $hosts) {
            foreach ($hosts as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'file_size' => 'integer',
            'completed_at' => 'datetime',
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
     * The post this video was downloaded for, when it was imported from Create post.
     *
     * @return HasOne<Post, $this>
     */
    public function post(): HasOne
    {
        return $this->hasOne(Post::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function hasFile(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->file_path !== null
            && Storage::disk('local')->exists($this->file_path);
    }

    /**
     * File name offered to the browser, built from the video title.
     */
    public function downloadName(): string
    {
        $name = Str::limit(Str::squish(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', (string) $this->title)), 120, '');

        return ($name !== '' ? $name : 'video-'.$this->id).'.'.pathinfo((string) $this->file_path, PATHINFO_EXTENSION);
    }

    public function deleteFile(): void
    {
        if ($this->file_path !== null) {
            Storage::disk('local')->delete($this->file_path);
        }
    }
}
