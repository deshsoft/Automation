<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Connectors\GoogleConnector;
use App\Services\Downloading\PhotoFallback;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads the video with YouTube's resumable upload protocol, in chunks, so
 * large files never need to fit in memory or in a single request.
 */
class YouTubePublisher implements Publisher
{
    /**
     * Chunk size must be a multiple of 256 KB.
     */
    public const CHUNK_SIZE = 8 * 1024 * 1024;

    public function __construct(private GoogleConnector $google, private PhotoFallback $photoFallback) {}

    public function publish(PostTarget $target): PublishResult
    {
        $post = $target->post;

        // YouTube's API cannot create photo posts: prepare it for the user to post by hand.
        if (! $post->isVideo() && $post->option('youtube.format') === 'post') {
            return PublishResult::manual();
        }

        // YouTube only takes videos, so a photo post gets a slideshow video made from its photos.
        $generatedVideo = $post->isVideo() ? null : $this->videoForPhoto($post);

        $path = $post->isVideo() ? $post->mediaLocalPath() : Storage::disk('public')->path((string) $generatedVideo);
        $mime = $post->isVideo() ? (string) $post->media_mime : 'video/mp4';

        if ($path === null || ! is_file($path)) {
            throw new PublishingException('The video file is missing on the server.');
        }

        $accessToken = $this->google->freshAccessToken($target->socialAccount);
        $fileSize = filesize($path);

        $uploadUrl = $this->startUploadSession($target, $accessToken, $fileSize, $mime);

        $videoId = $this->uploadChunks($uploadUrl, $accessToken, $path, $fileSize, $mime);

        $this->setThumbnail($target, $accessToken, $videoId);

        return PublishResult::published($videoId, 'https://youtu.be/'.$videoId);
    }

    /**
     * Upload the custom thumbnail. The video is already published, so a
     * rejected thumbnail (e.g. channel not verified) is only noted, not fatal.
     */
    private function setThumbnail(PostTarget $target, string $accessToken, string $videoId): void
    {
        $path = $target->post->thumbnailLocalPath();

        if ($path === null || ! is_file($path)) {
            return;
        }

        try {
            $response = Http::withToken($accessToken)
                ->withBody((string) file_get_contents($path), (string) mime_content_type($path))
                ->connectTimeout(10)
                ->timeout(60)
                ->post('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId='.urlencode($videoId));
        } catch (ConnectionException $exception) {
            $target->rememberState(['thumbnail_error' => 'Thumbnail not set: '.$exception->getMessage()]);

            return;
        }

        if ($response->failed()) {
            $target->rememberState(['thumbnail_error' => 'Video uploaded, but YouTube rejected the thumbnail: '
                .PublishingException::extractMessage($response)
                .' (custom thumbnails need a phone-verified channel: youtube.com/verify)']);
        }
    }

    /**
     * The video made from the post's photo; made now if it does not exist yet
     * (e.g. on Retry after ffmpeg was installed, or for an uploaded photo).
     */
    private function videoForPhoto(Post $post): string
    {
        $existing = $post->option('youtube_video_path');

        if (filled($existing) && Storage::disk('public')->exists($existing)) {
            return $existing;
        }

        if (! $post->isPhoto() || ! $post->hasMedia()) {
            throw new PublishingException('YouTube only accepts videos, and this post has no photo to make one from.');
        }

        $videoPath = $this->photoFallback->videoForPost($post);

        if ($videoPath === null) {
            throw new PublishingException('YouTube only accepts videos, and the video could not be made from the photo: '.$this->photoFallback->lastError);
        }

        $post->refresh();
        $post->update(['options' => [...($post->options ?? []), 'youtube_video_path' => $videoPath]]);

        return $videoPath;
    }

    /**
     * Change the title, description, tags and visibility of a published video.
     *
     * @throws PublishingException
     */
    public function updateLive(PostTarget $target): void
    {
        $post = $target->post;
        $accessToken = $this->google->freshAccessToken($target->socialAccount);

        $response = Http::withToken($accessToken)->connectTimeout(10)->timeout(60)
            ->put('https://www.googleapis.com/youtube/v3/videos?part=snippet,status', [
                'id' => $target->platform_post_id,
                'snippet' => array_filter([
                    'title' => $post->resolvedTitle(),
                    'description' => mb_substr($post->captionFor(Platform::YouTube), 0, 5000),
                    'tags' => $post->option('youtube.tags', []),
                    'categoryId' => (string) config('services.youtube.category_id'),
                ], fn (mixed $value) => $value !== []),
                'status' => [
                    'privacyStatus' => $post->option('youtube.privacy') ?: config('services.youtube.privacy'),
                    'selfDeclaredMadeForKids' => false,
                ],
            ]);

        if ($response->status() === 403 && str_contains(strtolower($response->body()), 'insufficient')) {
            throw new PublishingException('YouTube edit: this channel was connected before editing was supported. Reconnect it once on the Accounts page (+ YouTube channel), then save again.');
        }

        PublishingException::throwUnlessSuccessful($response, 'YouTube edit');
    }

    private function startUploadSession(PostTarget $target, string $accessToken, int $fileSize, string $mime): string
    {
        $post = $target->post;

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $fileSize,
                'X-Upload-Content-Type' => $mime,
            ])
            ->connectTimeout(10)
            ->timeout(60)
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
                'snippet' => array_filter([
                    'title' => $post->resolvedTitle(),
                    'description' => mb_substr($post->captionFor(Platform::YouTube), 0, 5000),
                    'tags' => $post->option('youtube.tags', []),
                    'categoryId' => (string) config('services.youtube.category_id'),
                ], fn (mixed $value) => $value !== []),
                'status' => [
                    'privacyStatus' => $post->option('youtube.privacy') ?: config('services.youtube.privacy'),
                    'selfDeclaredMadeForKids' => false,
                ],
            ]);

        PublishingException::throwUnlessSuccessful($response, 'YouTube');

        $uploadUrl = $response->header('Location');

        if ($uploadUrl === '') {
            throw new PublishingException('YouTube did not return an upload URL.');
        }

        return $uploadUrl;
    }

    private function uploadChunks(string $uploadUrl, string $accessToken, string $path, int $fileSize, string $mime): string
    {
        $handle = fopen($path, 'rb');

        try {
            $offset = 0;

            while (true) {
                fseek($handle, $offset);
                $chunk = (string) fread($handle, self::CHUNK_SIZE);
                $end = $offset + strlen($chunk) - 1;

                $response = Http::withToken($accessToken)
                    ->withoutRedirecting()
                    ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$fileSize}"])
                    ->withBody($chunk, $mime)
                    ->connectTimeout(10)
                    ->timeout(300)
                    ->put($uploadUrl);

                if ($response->status() === 308) {
                    $offset = $this->nextOffset($response->header('Range'), $end + 1);

                    continue;
                }

                PublishingException::throwUnlessSuccessful($response, 'YouTube upload');

                return (string) $response->json('id');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * YouTube reports the bytes it has received as "bytes=0-12345".
     */
    private function nextOffset(string $rangeHeader, int $fallback): int
    {
        if (preg_match('/bytes=0-(\d+)/', $rangeHeader, $matches) === 1) {
            return (int) $matches[1] + 1;
        }

        return $rangeHeader === '' ? 0 : $fallback;
    }
}
