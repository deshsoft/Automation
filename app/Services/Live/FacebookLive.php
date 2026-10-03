<?php

namespace App\Services\Live;

use App\Exceptions\PublishingException;
use App\Models\LiveStream;
use App\Models\LiveStreamTarget;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

/**
 * Creates and ends live videos on Facebook Pages. The video itself is sent by
 * OBS or a mobile app to the stream URL that Facebook returns for each Page.
 */
class FacebookLive
{
    /**
     * Create a live video on the Page. It goes live as soon as video arrives at the stream URL.
     *
     * @return array{id: string, stream_url: string, permalink: ?string}
     *
     * @throws PublishingException
     */
    public function create(SocialAccount $page, LiveStream $liveStream): array
    {
        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post($this->url($page->platform_account_id.'/live_videos'), array_filter([
            'status' => 'LIVE_NOW',
            'title' => $liveStream->title,
            'description' => (string) $liveStream->description,
            'access_token' => $page->access_token,
        ], fn (string $value) => $value !== ''));

        PublishingException::throwUnlessSuccessful($response, 'Facebook Live');

        $streamUrl = $response->json('secure_stream_url') ?? $response->json('stream_url');

        if (! is_string($streamUrl) || $streamUrl === '') {
            throw new PublishingException('Facebook Live: no stream URL was returned.');
        }

        $liveVideoId = (string) $response->json('id');

        return [
            'id' => $liveVideoId,
            'stream_url' => $streamUrl,
            'permalink' => $this->permalink($liveVideoId, $page),
        ];
    }

    /**
     * @throws PublishingException
     */
    public function end(LiveStreamTarget $target): void
    {
        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post($this->url((string) $target->platform_live_id), [
            'end_live_video' => 'true',
            'access_token' => $target->socialAccount->access_token,
        ]);

        PublishingException::throwUnlessSuccessful($response, 'Facebook Live');
    }

    /**
     * Share the main Page's live video on another Page.
     *
     * @return array{id: string, permalink: ?string}
     *
     * @throws PublishingException
     */
    public function share(SocialAccount $page, string $liveLink, ?string $message): array
    {
        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post($this->url($page->platform_account_id.'/feed'), array_filter([
            'link' => $liveLink,
            'message' => (string) $message,
            'access_token' => $page->access_token,
        ], fn (string $value) => $value !== ''));

        PublishingException::throwUnlessSuccessful($response, 'Facebook share');

        $postId = (string) $response->json('id');

        return ['id' => $postId, 'permalink' => $this->permalink($postId, $page) ?? 'https://www.facebook.com/'.$postId];
    }

    private function permalink(string $liveVideoId, SocialAccount $page): ?string
    {
        try {
            $permalink = Http::connectTimeout(5)->timeout(15)
                ->get($this->url($liveVideoId), ['fields' => 'permalink_url', 'access_token' => $page->access_token])
                ->json('permalink_url');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($permalink) || $permalink === '') {
            return null;
        }

        return str_starts_with($permalink, '/') ? 'https://www.facebook.com'.$permalink : $permalink;
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version').'/'.$path;
    }
}
