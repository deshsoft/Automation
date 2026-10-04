<?php

namespace App\Services\Downloading;

use App\Exceptions\DownloadException;
use App\Models\VideoDownload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Gets background music for photo videos, from a YouTube/Facebook link (audio
 * only, via yt-dlp) or a direct link to an audio file. Each link is downloaded
 * once and kept in storage/app/private/music for reuse.
 */
class MusicFetcher
{
    private const MAX_BYTES = 50 * 1024 * 1024;

    public ?string $lastError = null;

    public function __construct(private VideoDownloader $downloader) {}

    /**
     * Absolute path of the audio file, or null (see lastError).
     */
    public function fetch(string $url, int $userId): ?string
    {
        $this->lastError = null;
        $key = sha1($url);
        $directory = 'music';
        Storage::disk('local')->makeDirectory($directory);

        foreach (Storage::disk('local')->files($directory) as $existing) {
            if (str_starts_with(basename($existing), $key.'.')) {
                return Storage::disk('local')->path($existing);
            }
        }

        return VideoDownload::sourceOf($url) !== null
            ? $this->fromVideoSite($url, $userId, $directory, $key)
            : $this->fromAudioFile($url, $directory, $key);
    }

    private function fromVideoSite(string $url, int $userId, string $directory, string $key): ?string
    {
        // Not saved: only used to build the yt-dlp command for an audio-only download.
        $download = new VideoDownload(['url' => $url, 'quality' => 'audio']);
        $download->id = 'music-'.substr($key, 0, 12);
        $download->user_id = $userId;

        try {
            $file = $this->downloader->download($download);
        } catch (DownloadException $exception) {
            return $this->fail($exception->getMessage());
        }

        $target = $directory.'/'.$key.'.'.pathinfo($file['file_path'], PATHINFO_EXTENSION);
        Storage::disk('local')->move($file['file_path'], $target);

        return Storage::disk('local')->path($target);
    }

    private function fromAudioFile(string $url, string $directory, string $key): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'mp3';
        $target = Storage::disk('local')->path($directory.'/'.$key.'.'.$extension);

        try {
            $response = Http::connectTimeout(10)->timeout(120)->withOptions(['sink' => $target.'.tmp'])->get($url);
        } catch (Throwable $exception) {
            File::delete($target.'.tmp');

            return $this->fail('could not download '.parse_url($url, PHP_URL_HOST).' ('.mb_substr($exception->getMessage(), 0, 120).'). Allow that host in cPanel → Outgoing Connections.');
        }

        $size = (int) @filesize($target.'.tmp');

        if (! $response->successful() || $size === 0 || $size > self::MAX_BYTES) {
            File::delete($target.'.tmp');

            return $this->fail('the music file could not be downloaded (HTTP '.$response->status().($size > self::MAX_BYTES ? ', larger than 50 MB' : '').').');
        }

        File::move($target.'.tmp', $target);

        return $target;
    }

    private function fail(string $reason): null
    {
        $this->lastError = $reason;

        return null;
    }
}
