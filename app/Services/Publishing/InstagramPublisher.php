<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\PostTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Instagram publishing is two steps: create a media container, wait until
 * Instagram has downloaded and processed it, then publish the container.
 */
class InstagramPublisher implements Publisher
{
    public function publish(PostTarget $target): PublishResult
    {
        $containerId = $target->state['container_id'] ?? null;

        if ($containerId === null) {
            $containerId = $this->createContainer($target);
            $target->rememberState(['container_id' => $containerId]);

            return PublishResult::pending($target->post->isVideo() ? 30 : 5);
        }

        $statusCode = $this->containerStatus($target, $containerId);

        if ($statusCode === 'FINISHED') {
            $mediaId = $this->publishContainer($target, $containerId);

            return PublishResult::published($mediaId, $this->permalink($target, $mediaId));
        }

        return match ($statusCode) {
            'IN_PROGRESS' => PublishResult::pending(30),
            'PUBLISHED' => PublishResult::published($target->platform_post_id),
            default => throw new PublishingException("Instagram: media processing ended with status {$statusCode}. Check the video format (MP4/H.264, 3-90 seconds for Reels)."),
        };
    }

    private function createContainer(PostTarget $target): string
    {
        $post = $target->post;
        $account = $target->socialAccount;

        if (! $post->hasMedia()) {
            throw new PublishingException('Instagram needs a photo or video.');
        }

        $userTags = $this->userTags($post->option('instagram.user_tags', []), withPosition: ! $post->isVideo());

        $photoUrls = $post->photoUrls();

        if (count($photoUrls) > 1) {
            // Carousel: one container per photo (people tags go on the first), then the carousel itself.
            $children = [];

            foreach (array_slice($photoUrls, 0, 10) as $index => $photoUrl) {
                $child = $this->graph()->post($this->url($account->platform_account_id.'/media'), array_filter([
                    'image_url' => $photoUrl,
                    'is_carousel_item' => 'true',
                    'user_tags' => $index === 0 && $userTags !== [] ? (string) json_encode($userTags) : '',
                    'access_token' => $account->access_token,
                ], fn (string $value) => $value !== ''));

                PublishingException::throwUnlessSuccessful($child, 'Instagram photo '.($index + 1));
                $children[] = (string) $child->json('id');
            }

            $parameters = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];
            $userTags = [];
        } else {
            $parameters = $post->isVideo()
                ? ['media_type' => 'REELS', 'video_url' => $post->mediaUrl(), 'share_to_feed' => 'true', 'cover_url' => (string) $post->thumbnailUrl()]
                : ['image_url' => $post->mediaUrl()];
        }
        $collaborators = $post->option('instagram.collaborators', []);

        $response = $this->graph()->post($this->url($account->platform_account_id.'/media'), array_filter([
            ...$parameters,
            'caption' => $post->captionFor(Platform::Instagram),
            'location_id' => (string) $post->option('location_id'),
            'user_tags' => $userTags === [] ? '' : (string) json_encode($userTags),
            'collaborators' => $collaborators === [] ? '' : (string) json_encode(array_values($collaborators)),
            'access_token' => $account->access_token,
        ], fn (string $value) => $value !== ''));

        PublishingException::throwUnlessSuccessful($response, 'Instagram');

        return (string) $response->json('id');
    }

    private function containerStatus(PostTarget $target, string $containerId): string
    {
        $response = $this->graph()->get($this->url($containerId), [
            'fields' => 'status_code',
            'access_token' => $target->socialAccount->access_token,
        ]);

        PublishingException::throwUnlessSuccessful($response, 'Instagram');

        return (string) $response->json('status_code');
    }

    private function publishContainer(PostTarget $target, string $containerId): string
    {
        $account = $target->socialAccount;

        $response = $this->graph()->post($this->url($account->platform_account_id.'/media_publish'), [
            'creation_id' => $containerId,
            'access_token' => $account->access_token,
        ]);

        PublishingException::throwUnlessSuccessful($response, 'Instagram');

        return (string) $response->json('id');
    }

    /**
     * Photo tags need a position on the image; spread them along the middle.
     *
     * @param  list<string>  $usernames
     * @return list<array<string, mixed>>
     */
    private function userTags(array $usernames, bool $withPosition): array
    {
        $count = count($usernames);

        return array_values(array_map(fn (string $username, int $index) => $withPosition
            ? ['username' => $username, 'x' => round(($index + 1) / ($count + 1), 2), 'y' => 0.5]
            : ['username' => $username], $usernames, array_keys($usernames)));
    }

    private function permalink(PostTarget $target, string $mediaId): ?string
    {
        try {
            $permalink = $this->graph()->get($this->url($mediaId), [
                'fields' => 'permalink',
                'access_token' => $target->socialAccount->access_token,
            ])->json('permalink');
        } catch (\Throwable) {
            return null;
        }

        return is_string($permalink) && $permalink !== '' ? $permalink : null;
    }

    private function graph(): PendingRequest
    {
        return Http::asForm()->connectTimeout(10)->timeout(60);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version').'/'.$path;
    }
}
