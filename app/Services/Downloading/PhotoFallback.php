<?php

namespace App\Services\Downloading;

use App\Models\Post;
use App\Services\LinkPreviewer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * For links to photo posts (no video to download): takes the post's photo,
 * and for YouTube, which only accepts videos, turns it into a short vertical video.
 */
class PhotoFallback
{
    /**
     * Length of the video made from a photo, in seconds.
     */
    public const VIDEO_SECONDS = 15;

    private const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    public function __construct(private LinkPreviewer $previewer, private VideoDownloader $downloader) {}

    /**
     * Save the link's main photo as a JPG in public media. Returns its path, or null.
     */
    public function importPhoto(string $url, Post $post): ?string
    {
        $imageUrl = $this->previewer->preview($url)['image'] ?? null;

        if (! is_string($imageUrl) || ! str_starts_with($imageUrl, 'https://')) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])->connectTimeout(10)->timeout(60)->get($imageUrl);
        } catch (Throwable) {
            return null;
        }

        $body = $response->successful() ? $response->body() : '';

        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES || @getimagesizefromstring($body) === false) {
            return null;
        }

        // Instagram only accepts JPG, so always store a JPG.
        $image = @imagecreatefromstring($body);

        if ($image === false) {
            return null;
        }

        $path = 'media/import-'.$post->id.'-'.Str::random(8).'.jpg';
        ob_start();
        imagejpeg($image, null, 90);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    /**
     * Make a 1080x1920 video (Shorts size) from the photo, with the photo centred
     * on a blurred copy of itself and a silent sound track. Returns its path, or null.
     */
    public function makeVideo(string $photoPath, Post $post): ?string
    {
        $ffmpeg = $this->downloader->ffmpeg();

        if ($ffmpeg === null) {
            return null;
        }

        $videoPath = 'media/import-'.$post->id.'-'.Str::random(8).'.mp4';
        File::ensureDirectoryExists(dirname(Storage::disk('public')->path($videoPath)));

        $result = Process::timeout(300)->env(VideoDownloader::environment())->run([
            $ffmpeg, '-y', '-loglevel', 'error',
            '-loop', '1', '-i', Storage::disk('public')->path($photoPath),
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-t', (string) self::VIDEO_SECONDS,
            '-filter_complex', '[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:2[bg];'
                .'[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];'
                .'[bg][fg]overlay=(W-w)/2:(H-h)/2,format=yuv420p[v]',
            '-map', '[v]', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-r', '30',
            '-c:a', 'aac', '-shortest', '-movflags', '+faststart',
            Storage::disk('public')->path($videoPath),
        ]);

        if ($result->failed() || ! Storage::disk('public')->exists($videoPath)) {
            Storage::disk('public')->delete($videoPath);

            return null;
        }

        return $videoPath;
    }
}
