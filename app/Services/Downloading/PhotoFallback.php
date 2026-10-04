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

    /**
     * Video encoder settings, tried in order until one works on this server.
     *
     * @var list<list<string>>
     */
    private const ENCODERS = [
        ['-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'stillimage', '-x264-params', 'threads=2:lookahead-threads=1'],
        ['-c:v', 'libx264', '-preset', 'ultrafast', '-x264-params', 'threads=1:lookahead-threads=1:sliced-threads=0'],
        ['-c:v', 'mpeg4', '-q:v', '3'],
    ];

    /**
     * Why the last importPhoto() or makeVideo() call returned null.
     */
    public ?string $lastError = null;

    public function __construct(private LinkPreviewer $previewer, private VideoDownloader $downloader) {}

    /**
     * Save the link's main photo as a JPG in public media. Returns its path, or null.
     */
    public function importPhoto(string $url, Post $post): ?string
    {
        $this->lastError = null;
        $imageUrl = $this->previewer->preview($url)['image'] ?? null;

        if (! is_string($imageUrl) || ! str_starts_with($imageUrl, 'https://')) {
            return $this->fail('no photo found in the link (the post may be private, or the server cannot open facebook.com).');
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])->connectTimeout(10)->timeout(60)->get($imageUrl);
        } catch (Throwable $exception) {
            return $this->fail('could not download the photo from '.parse_url($imageUrl, PHP_URL_HOST).' ('.mb_substr($exception->getMessage(), 0, 120).'). Allow that host in cPanel → Outgoing Connections.');
        }

        $body = $response->successful() ? $response->body() : '';

        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES || @getimagesizefromstring($body) === false) {
            return $this->fail('the photo could not be read (HTTP '.$response->status().' from '.parse_url($imageUrl, PHP_URL_HOST).').');
        }

        // Instagram only accepts JPG, so always store a JPG.
        $image = @imagecreatefromstring($body);

        if ($image === false) {
            return $this->fail('the photo format is not supported.');
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
        $this->lastError = null;
        $ffmpeg = $this->downloader->ffmpeg();

        if ($ffmpeg === null) {
            return $this->fail('ffmpeg is not installed. Open System → "Install video downloader (2/3: ffmpeg)".');
        }

        $videoPath = 'media/import-'.$post->id.'-'.Str::random(8).'.mp4';
        File::ensureDirectoryExists(dirname(Storage::disk('public')->path($videoPath)));
        $errors = [];

        // Shared hosts limit how many threads an account may start, and libx264
        // starts one per CPU core by default. Try it with few threads first,
        // then fall back to ffmpeg's built-in MPEG-4 encoder (also accepted by YouTube).
        foreach (self::ENCODERS as $encoder) {
            $result = Process::timeout(300)->env(VideoDownloader::environment())->run([
                $ffmpeg, '-y', '-loglevel', 'error', '-threads', '1', '-filter_threads', '1',
                '-loop', '1', '-i', Storage::disk('public')->path($photoPath),
                '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
                '-t', (string) self::VIDEO_SECONDS,
                // Blur a small copy for the background (much less CPU on shared hosting).
                '-filter_complex', '[0:v]scale=108:192:force_original_aspect_ratio=increase,crop=108:192,boxblur=4:1,scale=1080:1920[bg];'
                    .'[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];'
                    .'[bg][fg]overlay=(W-w)/2:(H-h)/2,format=yuv420p[v]',
                '-map', '[v]', '-map', '1:a', '-r', '25',
                ...$encoder,
                '-c:a', 'aac', '-shortest', '-movflags', '+faststart',
                Storage::disk('public')->path($videoPath),
            ]);

            if ($result->successful() && Storage::disk('public')->exists($videoPath) && Storage::disk('public')->size($videoPath) > 0) {
                return $videoPath;
            }

            Storage::disk('public')->delete($videoPath);
            $output = trim($result->errorOutput() ?: $result->output());
            $errors[] = $encoder[1].': '.($output !== '' ? mb_substr($output, -200) : 'exit code '.$result->exitCode().', the host may have stopped it');
        }

        return $this->fail('ffmpeg could not make the video ('.implode(' | ', $errors).').');
    }

    private function fail(string $reason): null
    {
        $this->lastError = $reason;

        return null;
    }
}
