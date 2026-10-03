<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\PostTarget;
use App\Services\Connectors\TikTokConnector;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts with TikTok's Content Posting API. TikTok downloads the media from
 * our public URL (the domain must be verified in the TikTok developer portal),
 * then processes it asynchronously.
 */
class TikTokPublisher implements Publisher
{
    private const API = 'https://open.tiktokapis.com/v2/post/publish/';

    public function __construct(private TikTokConnector $tiktok) {}

    public function publish(PostTarget $target): PublishResult
    {
        $accessToken = $this->tiktok->freshAccessToken($target->socialAccount);
        $publishId = $target->state['publish_id'] ?? null;

        if ($publishId === null) {
            $target->rememberState(['publish_id' => $this->startPost($target, $accessToken)]);

            return PublishResult::pending(30);
        }

        $status = $this->call($accessToken, 'status/fetch/', ['publish_id' => $publishId]);

        return match ($status->json('data.status')) {
            'PUBLISH_COMPLETE' => PublishResult::published((string) ($status->json('data.publicaly_available_post_id.0') ?? $publishId)),
            'SEND_TO_USER_INBOX' => PublishResult::published($publishId),
            'FAILED' => throw new PublishingException('TikTok: '.($status->json('data.fail_reason') ?? 'processing failed')),
            default => PublishResult::pending(30),
        };
    }

    private function startPost(PostTarget $target, string $accessToken): string
    {
        $post = $target->post;

        if (! $post->hasMedia()) {
            throw new PublishingException('TikTok needs a photo or video.');
        }

        $privacyLevel = $post->option('tiktok.privacy') === 'private' ? 'SELF_ONLY' : $this->privacyLevel($accessToken);
        $caption = $post->captionFor(Platform::TikTok);
        $disableComment = ! $post->option('tiktok.allow_comments', true);

        if ($post->isVideo()) {
            $response = $this->call($accessToken, 'video/init/', [
                'post_info' => [
                    'title' => mb_substr($caption, 0, 2200),
                    'privacy_level' => $privacyLevel,
                    'disable_duet' => ! $post->option('tiktok.allow_duet', true),
                    'disable_comment' => $disableComment,
                    'disable_stitch' => ! $post->option('tiktok.allow_stitch', true),
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $post->mediaUrl(),
                ],
            ]);
        } else {
            $response = $this->call($accessToken, 'content/init/', [
                'post_info' => [
                    'title' => mb_substr($post->resolvedTitle(), 0, 90),
                    'description' => mb_substr($caption, 0, 4000),
                    'privacy_level' => $privacyLevel,
                    'disable_comment' => $disableComment,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_cover_index' => 0,
                    'photo_images' => [$post->mediaUrl()],
                ],
                'post_mode' => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ]);
        }

        return (string) $response->json('data.publish_id');
    }

    /**
     * Unaudited TikTok apps may only post privately, so pick public only when allowed.
     */
    private function privacyLevel(string $accessToken): string
    {
        $options = $this->call($accessToken, 'creator_info/query/', [])->json('data.privacy_level_options', []);

        foreach (['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'] as $level) {
            if (in_array($level, $options, true)) {
                return $level;
            }
        }

        return 'SELF_ONLY';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $accessToken, string $endpoint, array $payload): Response
    {
        $response = Http::withToken($accessToken)->connectTimeout(10)->timeout(60)
            ->withBody($payload === [] ? '{}' : (string) json_encode($payload), 'application/json; charset=UTF-8')
            ->post(self::API.$endpoint);

        PublishingException::throwUnlessSuccessful($response, 'TikTok');

        $errorCode = $response->json('error.code');

        if ($errorCode !== null && $errorCode !== 'ok') {
            throw new PublishingException('TikTok: '.($response->json('error.message') ?: $errorCode));
        }

        return $response;
    }
}
