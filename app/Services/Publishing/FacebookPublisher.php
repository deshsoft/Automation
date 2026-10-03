<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\TargetStatus;
use App\Exceptions\PublishingException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\LinkPreviewer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Photos and videos are uploaded as files (not as links), so publishing works
 * even when the server is not reachable from the internet (e.g. localhost).
 *
 * In share mode, the main Page posts first and every other Page then shares
 * the main Page's post.
 */
class FacebookPublisher implements Publisher
{
    public function __construct(private LinkPreviewer $linkPreviewer) {}

    public function publish(PostTarget $target): PublishResult
    {
        if ($target->isShare()) {
            return $this->shareMainPost($target);
        }

        $post = $target->post;
        $account = $target->socialAccount;
        $caption = $post->captionFor(Platform::Facebook);
        $place = (string) $post->option('location_id');

        if (filled($post->option('link'))) {
            // "From a link" with Facebook set to share: share the link even when
            // a video was downloaded for the other platforms.
            $response = Http::asForm()->connectTimeout(10)->timeout(60)->post($this->url($account->platform_account_id.'/feed'), array_filter([
                'message' => $caption,
                'link' => $this->linkPreviewer->canonicalFacebookUrl((string) $post->option('link')),
                'place' => $place,
                'access_token' => $account->access_token,
            ], fn (string $value) => $value !== ''));
        } elseif ($post->isVideo()) {
            $response = $this->upload($post, $this->url($account->platform_account_id.'/videos', video: true), [
                'description' => $caption,
                'title' => (string) $post->title,
                'access_token' => $account->access_token,
            ]);
        } elseif ($post->isPhoto()) {
            $response = $this->upload($post, $this->url($account->platform_account_id.'/photos'), [
                'caption' => $caption,
                'place' => $place,
                'access_token' => $account->access_token,
            ]);
        } else {
            $response = Http::asForm()->connectTimeout(10)->timeout(60)->post($this->url($account->platform_account_id.'/feed'), array_filter([
                'message' => $caption,
                'place' => $place,
                'access_token' => $account->access_token,
            ], fn (string $value) => $value !== ''));
        }

        PublishingException::throwUnlessSuccessful($response, 'Facebook');

        $postId = (string) ($response->json('post_id') ?? $response->json('id'));

        return PublishResult::published($postId, $this->permalink($postId, $account));
    }

    /**
     * Wait for the main Page's post, then share its link on this Page.
     */
    private function shareMainPost(PostTarget $target): PublishResult
    {
        $mainTarget = $target->post->targets()
            ->with('socialAccount')
            ->where('social_account_id', $target->post->share_from_account_id)
            ->first();

        if ($mainTarget === null) {
            throw new PublishingException('The main Page for sharing is not part of this post.');
        }

        if ($mainTarget->status === TargetStatus::Failed) {
            throw new PublishingException('Nothing to share: posting on the main Page ('.$mainTarget->socialAccount->name.') failed.');
        }

        if ($mainTarget->status !== TargetStatus::Published) {
            return PublishResult::pending(30);
        }

        $link = $mainTarget->permalink ?: 'https://www.facebook.com/'.$mainTarget->platform_post_id;
        $account = $target->socialAccount;

        $response = Http::asForm()->connectTimeout(10)->timeout(60)->post($this->url($account->platform_account_id.'/feed'), array_filter([
            'link' => $link,
            'message' => (string) $target->post->option('share_message'),
            'access_token' => $account->access_token,
        ], fn (string $value) => $value !== ''));

        PublishingException::throwUnlessSuccessful($response, 'Facebook share');

        $postId = (string) $response->json('id');

        return PublishResult::published($postId, $this->permalink($postId, $account));
    }

    /**
     * Ask Facebook for the public link of a post. Falls back to a constructed link.
     */
    private function permalink(string $postId, SocialAccount $account): string
    {
        $fallback = 'https://www.facebook.com/'.$postId;

        try {
            $permalink = Http::connectTimeout(5)->timeout(15)
                ->get($this->url($postId), ['fields' => 'permalink_url', 'access_token' => $account->access_token])
                ->json('permalink_url');
        } catch (\Throwable) {
            return $fallback;
        }

        if (! is_string($permalink) || $permalink === '') {
            return $fallback;
        }

        return str_starts_with($permalink, '/') ? 'https://www.facebook.com'.$permalink : $permalink;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function upload(Post $post, string $url, array $fields): Response
    {
        $path = $post->mediaLocalPath();

        if ($path === null || ! is_file($path)) {
            throw new PublishingException('The photo/video file is missing on the server.');
        }

        // Guzzle wraps the handle in a stream and closes it once the upload is done.
        $request = Http::connectTimeout(10)
            ->timeout($post->isVideo() ? 900 : 120)
            ->attach('source', fopen($path, 'rb'), basename($path));

        $thumbnail = $post->thumbnailLocalPath();

        if ($thumbnail !== null && is_file($thumbnail)) {
            $request->attach('thumb', fopen($thumbnail, 'rb'), basename($thumbnail));
        }

        return $request->post($url, array_filter($fields, fn (string $value) => $value !== ''));
    }

    private function url(string $path, bool $video = false): string
    {
        $host = $video ? 'https://graph-video.facebook.com/' : 'https://graph.facebook.com/';

        return $host.config('services.facebook.graph_version').'/'.$path;
    }
}
