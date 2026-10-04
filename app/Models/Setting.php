<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App-wide settings changed from the Settings page (no .env editing needed).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Every setting with its default.
     *
     * @return array<string, int>
     */
    public static function defaults(): array
    {
        return [
            'media_keep_days' => 7,
            'posts_keep_days' => 0,
            'downloads_keep_days' => (int) config('services.downloader.keep_days', 3),
        ];
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function integer(string $key): int
    {
        try {
            $value = static::query()->whereKey($key)->value('value');
        } catch (Throwable) {
            $value = null;
        }

        return $value !== null ? (int) $value : (int) (self::defaults()[$key] ?? 0);
    }

    public static function put(string $key, int|string|null $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
    }
}
