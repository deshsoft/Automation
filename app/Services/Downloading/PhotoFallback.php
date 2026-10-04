<?php

namespace App\Services\Downloading;

use App\Enums\Platform;
use App\Models\Post;
use App\Services\LinkPreviewer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Photos instead of a video: takes the photos of a link (all of them when the
 * post is on one of the user's connected Pages), and for YouTube, which only
 * accepts videos, turns them into a vertical slideshow with optional music.
 */
class PhotoFallback
{
    /**
     * Length of the video made from a single photo, in seconds.
     */
    public const VIDEO_SECONDS = 15;

    /**
     * How long each photo shows in a slideshow of several photos.
     */
    public const SECONDS_PER_PHOTO = 4;

    public const MAX_PHOTOS = 10;

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
     * Why the last call returned nothing.
     */
    public ?string $lastError = null;

    public function __construct(
        private LinkPreviewer $previewer,
        private VideoDownloader $downloader,
        private MusicFetcher $music,
    ) {}

    /**
     * Save the photos of a link as JPGs in public media. Returns their paths (empty on failure).
     *
     * @return list<string>
     */
    public function importPhotos(string $url, Post $post): array
    {
        $this->lastError = null;
        $imageUrls = $this->photoUrlsFromConnectedPage($url, $post);

        if ($imageUrls === []) {
            $imageUrl = $this->previewer->preview($url)['image'] ?? null;

            if (! is_string($imageUrl) || ! str_starts_with($imageUrl, 'https://')) {
                $this->fail('no photo found in the link (the post may be private, or the server cannot open facebook.com).');

                return [];
            }

            $imageUrls = [$imageUrl];
        }

        $paths = [];

        foreach (array_slice($imageUrls, 0, self::MAX_PHOTOS) as $imageUrl) {
            if ($path = $this->saveImage($imageUrl, $post)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function importPhoto(string $url, Post $post): ?string
    {
        return $this->importPhotos($url, $post)[0] ?? null;
    }

    /**
     * The YouTube video for a photo post: all its photos, with its background music.
     */
    public function videoForPost(Post $post): ?string
    {
        $this->lastError = null;
        $musicPath = null;

        if (filled($post->option('music_url'))) {
            $musicPath = $this->music->fetch((string) $post->option('music_url'), (int) $post->user_id);

            if ($musicPath === null) {
                return $this->fail('the background music could not be downloaded: '.$this->music->lastError);
            }
        }

        return $this->makeVideo($post->photoPaths(), $post, $musicPath);
    }

    /**
     * Make a 1080x1920 slideshow (Shorts size): each photo centred on a blurred
     * copy of itself, with a short fade between photos. Returns its path, or null.
     *
     * @param  string|list<string>  $photoPaths
     */
    public function makeVideo(string|array $photoPaths, Post $post, ?string $musicPath = null): ?string
    {
        $photoPaths = array_values((array) $photoPaths);
        $ffmpeg = $this->downloader->ffmpeg();

        if ($ffmpeg === null) {
            return $this->fail('ffmpeg is not installed. Open System → "Install video downloader (2/3: ffmpeg)".');
        }

        if ($photoPaths === []) {
            return $this->fail('there is no photo to make the video from.');
        }

        $count = count($photoPaths);
        $perPhoto = $count === 1 ? self::VIDEO_SECONDS : self::SECONDS_PER_PHOTO;
        $total = $perPhoto * $count;

        $inputs = [];
        $filters = [];

        foreach ($photoPaths as $index => $path) {
            array_push($inputs, '-loop', '1', '-t', (string) $perPhoto, '-i', Storage::disk('public')->path($path));

            $fades = $count > 1 ? ',fade=t=in:st=0:d=0.4,fade=t=out:st='.($perPhoto - 0.4).':d=0.4' : '';
            // Blur a small copy for the background (much less CPU on shared hosting).
            $filters[] = "[{$index}:v]split[b{$index}][f{$index}];"
                ."[b{$index}]scale=108:192:force_original_aspect_ratio=increase,crop=108:192,boxblur=4:1,scale=1080:1920,setsar=1[bg{$index}];"
                ."[f{$index}]scale=1080:1920:force_original_aspect_ratio=decrease,setsar=1[fg{$index}];"
                ."[bg{$index}][fg{$index}]overlay=(W-w)/2:(H-h)/2,fps=25,format=yuv420p{$fades}[v{$index}]";
        }

        $filters[] = implode('', array_map(fn (int $index) => "[v{$index}]", array_keys($photoPaths)))."concat=n={$count}:v=1:a=0[v]";

        if ($musicPath !== null) {
            array_push($inputs, '-stream_loop', '-1', '-i', $musicPath);
            $filters[] = "[{$count}:a]atrim=0:{$total},asetpts=N/SR/TB,afade=t=in:st=0:d=1,afade=t=out:st=".max(0, $total - 2).':d=2[a]';
        } else {
            array_push($inputs, '-f', 'lavfi', '-t', (string) $total, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100');
            $filters[] = "[{$count}:a]anull[a]";
        }

        $videoPath = 'media/import-'.$post->id.'-'.Str::random(8).'.mp4';
        File::ensureDirectoryExists(dirname(Storage::disk('public')->path($videoPath)));
        $errors = [];

        // Shared hosts limit how many threads an account may start, and libx264
        // starts one per CPU core by default. Try it with few threads first,
        // then fall back to ffmpeg's built-in MPEG-4 encoder (also accepted by YouTube).
        foreach (self::ENCODERS as $encoder) {
            $result = Process::timeout(600)->env(VideoDownloader::environment())->run([
                $ffmpeg, '-y', '-loglevel', 'error', '-threads', '1', '-filter_threads', '1',
                ...$inputs,
                '-filter_complex', implode(';', $filters),
                '-map', '[v]', '-map', '[a]',
                ...$encoder,
                '-c:a', 'aac', '-b:a', '128k', '-t', (string) $total, '-movflags', '+faststart',
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

    /**
     * All photo URLs of a post on one of the user's own Pages, read with the
     * Page's token. Facebook does not give other Pages' photos to apps.
     *
     * @return list<string>
     */
    private function photoUrlsFromConnectedPage(string $url, Post $post): array
    {
        $canonical = $this->previewer->canonicalFacebookUrl($url);

        if (preg_match('#facebook\.com/.+?/(?:posts|videos|photos)/(?:[^/]+/)?(\d{8,})#', $canonical, $matches) !== 1
            && preg_match('#[?&]fbid=(\d{8,})#', $canonical, $matches) !== 1) {
            return [];
        }

        $objectId = $matches[1];
        $pages = $post->user?->socialAccounts()->where('platform', Platform::Facebook)->get() ?? collect();

        foreach ($pages as $page) {
            foreach (["{$page->platform_account_id}_{$objectId}", $objectId] as $nodeId) {
                try {
                    $response = Http::connectTimeout(10)->timeout(30)->get('https://graph.facebook.com/'.config('services.facebook.graph_version').'/'.$nodeId, [
                        'fields' => 'attachments{media{image{src}},subattachments.limit(20){media{image{src}}}}',
                        'access_token' => $page->access_token,
                    ]);
                } catch (Throwable) {
                    continue;
                }

                $attachment = $response->successful() ? $response->json('attachments.data.0') : null;

                if (! is_array($attachment)) {
                    continue;
                }

                $sources = collect(data_get($attachment, 'subattachments.data', []))
                    ->map(fn ($item) => data_get($item, 'media.image.src'))
                    ->prepend(data_get($attachment, 'subattachments') ? null : data_get($attachment, 'media.image.src'))
                    ->filter(fn ($src) => is_string($src) && str_starts_with($src, 'https://'))
                    ->values()
                    ->all();

                if ($sources !== []) {
                    return $sources;
                }
            }
        }

        return [];
    }

    private function saveImage(string $imageUrl, Post $post): ?string
    {
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

    private function fail(string $reason): null
    {
        $this->lastError = $reason;

        return null;
    }
}
