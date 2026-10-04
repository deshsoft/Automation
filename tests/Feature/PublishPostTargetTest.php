<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\LinkPreviewer;
use App\Services\Publishing\YouTubePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishPostTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.facebook.graph_version' => 'v24.0']);
    }

    private function targetFor(SocialAccount $account, Post $post): PostTarget
    {
        return PostTarget::factory()->forPostAndAccount($post, $account)->create();
    }

    private function multipartField(Request $request, string $name): ?string
    {
        $part = collect($request->data())->firstWhere('name', $name);

        if ($part === null) {
            return null;
        }

        $contents = $part['contents'];

        return is_resource($contents) ? (string) stream_get_contents($contents, -1, 0) : (string) $contents;
    }

    private function runJob(PostTarget $target): PublishPostTarget
    {
        $job = (new PublishPostTarget($target->fresh()))->withFakeQueueInteractions();
        $job->handle(app());

        return $job;
    }

    public function test_facebook_photo_file_is_uploaded_and_post_marked_published(): void
    {
        Storage::disk('public')->put('media/photo.jpg', 'jpeg-bytes');
        Http::fake([
            'graph.facebook.com/v24.0/111/photos' => Http::response(['id' => 'photo-1', 'post_id' => '111_999']),
        ]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111', 'access_token' => 'page-token']);
        $post = Post::factory()->for($page->user)->withPhoto()->create(['caption' => 'Hello']);
        $target = $this->targetFor($page, $post);

        $this->runJob($target)->assertNotReleased();

        Http::assertSent(fn (Request $request) => $request->isMultipart()
            && $this->multipartField($request, 'caption') === 'Hello'
            && $this->multipartField($request, 'access_token') === 'page-token'
            && $this->multipartField($request, 'source') === 'jpeg-bytes');
        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertSame('111_999', $target->fresh()->platform_post_id);
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_facebook_fails_clearly_when_media_file_is_missing(): void
    {
        $page = SocialAccount::factory()->facebook()->create();
        $target = $this->targetFor($page, Post::factory()->for($page->user)->withPhoto()->create());

        $this->runJob($target);

        Http::assertNothingSent();
        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertStringContainsString('missing', $target->fresh()->error);
    }

    public function test_facebook_text_post_uses_the_feed_endpoint(): void
    {
        Http::fake(['graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_5'])]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $target = $this->targetFor($page, Post::factory()->for($page->user)->create(['caption' => 'Text']));

        $this->runJob($target);

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
    }

    public function test_facebook_shares_a_link_with_the_caption_as_message(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_8']),
            'graph.facebook.com/v24.0/111_8*' => Http::response(['permalink_url' => 'https://www.facebook.com/111/posts/8']),
        ]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($page->user)->create([
            'caption' => 'Must read',
            'options' => ['link' => 'https://www.facebook.com/someone/posts/12345'],
        ]);
        $target = $this->targetFor($page, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/111/feed')
            && $request['link'] === 'https://www.facebook.com/someone/posts/12345'
            && $request['message'] === 'Must read');
        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
    }

    public function test_facebook_short_share_link_is_turned_into_the_real_post_address(): void
    {
        $this->app->instance(LinkPreviewer::class, new LinkPreviewer(fn () => ['157.240.1.35']));
        Http::fake([
            'www.facebook.com/share/p/abc*' => Http::response('', 302, ['Location' => 'https://web.facebook.com/SelimPage/posts/some-text-slug/12345/']),
            'web.facebook.com/SelimPage/*' => Http::response('<meta property="og:url" content="https://web.facebook.com/SelimPage/posts/some-text-slug/12345/">'),
            'graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_8']),
            'graph.facebook.com/v24.0/111_8*' => Http::response([]),
        ]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($page->user)->create(['options' => ['link' => 'https://www.facebook.com/share/p/abc/']]);
        $target = $this->targetFor($page, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/111/feed')
            && $request['link'] === 'https://www.facebook.com/SelimPage/posts/12345');
    }

    public function test_normal_post_address_is_cleaned_without_any_request(): void
    {
        $previewer = new LinkPreviewer(fn () => []);

        $this->assertSame(
            'https://www.facebook.com/SelimPage/posts/12345',
            $previewer->canonicalFacebookUrl('https://web.facebook.com/SelimPage/posts/%E0%A6%B0-text/12345/'),
        );
        $this->assertSame(
            'https://www.facebook.com/reel/962132813601229',
            $previewer->canonicalFacebookUrl('https://www.facebook.com/reel/962132813601229/?rdid=abc&share_url=x'),
        );
        $this->assertSame(
            'https://www.facebook.com/SelimPage/videos/777',
            $previewer->canonicalFacebookUrl('https://m.facebook.com/SelimPage/videos/some-title/777/?mibextid=xyz'),
        );
        $this->assertSame('https://example.com/news', $previewer->canonicalFacebookUrl('https://example.com/news'));
        Http::assertNothingSent();
    }

    public function test_facebook_shares_the_link_even_when_a_video_was_downloaded_for_other_platforms(): void
    {
        Storage::disk('public')->put('media/video.mp4', 'mp4-bytes');
        Http::fake([
            'graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_3']),
            'graph.facebook.com/v24.0/111_3*' => Http::response([]),
        ]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($page->user)->withVideo()->create(['options' => ['link' => 'https://www.youtube.com/watch?v=abc']]);
        $target = $this->targetFor($page, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/111/feed') && $request['link'] === 'https://www.youtube.com/watch?v=abc');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph-video'));
    }

    public function test_facebook_video_file_is_uploaded_to_the_video_host(): void
    {
        Storage::disk('public')->put('media/video.mp4', 'mp4-bytes');
        Http::fake(['graph-video.facebook.com/v24.0/111/videos' => Http::response(['id' => 'video-7'])]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $target = $this->targetFor($page, Post::factory()->for($page->user)->withVideo()->create(['caption' => 'Speech']));

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => $this->multipartField($request, 'source') === 'mp4-bytes'
            && $this->multipartField($request, 'description') === 'Speech');
        $this->assertSame('video-7', $target->fresh()->platform_post_id);
    }

    public function test_api_rejection_marks_target_failed_without_retrying(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);
        $page = SocialAccount::factory()->facebook()->create();
        $post = Post::factory()->for($page->user)->create();
        $target = $this->targetFor($page, $post);

        $this->runJob($target)->assertNotReleased();

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertStringContainsString('Invalid OAuth access token', $target->fresh()->error);
        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
    }

    public function test_server_error_is_thrown_so_the_queue_retries(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('Service unavailable', 503)]);
        $page = SocialAccount::factory()->facebook()->create();
        $target = $this->targetFor($page, Post::factory()->for($page->user)->create());

        $this->expectException(RequestException::class);

        try {
            $this->runJob($target);
        } finally {
            $this->assertSame(TargetStatus::Processing, $target->fresh()->status);
        }
    }

    public function test_failed_hook_marks_target_failed_after_retries_run_out(): void
    {
        $page = SocialAccount::factory()->facebook()->create();
        $post = Post::factory()->for($page->user)->create();
        $target = $this->targetFor($page, $post);

        (new PublishPostTarget($target))->failed(new \RuntimeException('Connection timed out'));

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertSame('Connection timed out', $target->fresh()->error);
        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
    }

    public function test_one_success_and_one_failure_marks_post_partially_failed(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_1']),
            'graph.facebook.com/v24.0/222/feed' => Http::response(['error' => ['message' => 'Page restricted']], 403),
        ]);
        $first = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $second = SocialAccount::factory()->for($first->user)->facebook()->create(['platform_account_id' => '222']);
        $post = Post::factory()->for($first->user)->create();
        $firstTarget = $this->targetFor($first, $post);
        $secondTarget = $this->targetFor($second, $post);

        $this->runJob($firstTarget);
        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);

        $this->runJob($secondTarget);
        $this->assertSame(PostStatus::PartiallyFailed, $post->fresh()->status);
    }

    public function test_disabled_account_is_not_published(): void
    {
        $page = SocialAccount::factory()->facebook()->inactive()->create();
        $target = $this->targetFor($page, Post::factory()->for($page->user)->create());

        $this->runJob($target);

        Http::assertNothingSent();
        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
    }

    public function test_cancelled_post_is_skipped(): void
    {
        $page = SocialAccount::factory()->facebook()->create();
        $target = $this->targetFor($page, Post::factory()->for($page->user)->create(['status' => PostStatus::Cancelled]));

        $this->runJob($target);

        Http::assertNothingSent();
        $this->assertSame(TargetStatus::Pending, $target->fresh()->status);
    }

    public function test_instagram_creates_container_waits_then_publishes(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/ig-1/media' => Http::response(['id' => 'container-1']),
            'graph.facebook.com/v24.0/container-1*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
            'graph.facebook.com/v24.0/ig-1/media_publish' => Http::response(['id' => 'ig-media-9']),
        ]);
        $instagram = SocialAccount::factory()->instagram()->create(['platform_account_id' => 'ig-1']);
        $target = $this->targetFor($instagram, Post::factory()->for($instagram->user)->withVideo()->create(['caption' => 'Reel']));

        $this->runJob($target)->assertReleased(30);
        $this->assertSame('container-1', $target->fresh()->state['container_id']);
        Http::assertSent(fn (Request $request) => $request['media_type'] === 'REELS' && isset($request['video_url']));

        $this->runJob($target)->assertReleased(30);
        $this->assertSame(TargetStatus::Processing, $target->fresh()->status);

        $this->runJob($target)->assertNotReleased();
        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertSame('ig-media-9', $target->fresh()->platform_post_id);
    }

    public function test_instagram_processing_error_fails_the_target(): void
    {
        Http::fake(['graph.facebook.com/v24.0/container-1*' => Http::response(['status_code' => 'ERROR'])]);
        $instagram = SocialAccount::factory()->instagram()->create();
        $target = $this->targetFor($instagram, Post::factory()->for($instagram->user)->withVideo()->create());
        $target->update(['state' => ['container_id' => 'container-1']]);

        $this->runJob($target);

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
    }

    public function test_youtube_refreshes_token_and_uploads_video_in_chunks(): void
    {
        $chunkSize = YouTubePublisher::CHUNK_SIZE;
        Storage::disk('public')->put('media/video.mp4', str_repeat('v', $chunkSize + 1000));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example.test/session-1']),
            'upload.example.test/session-1' => Http::sequence()
                ->push('', 308, ['Range' => 'bytes=0-'.($chunkSize - 1)])
                ->push(['id' => 'yt-video-1'], 201),
        ]);
        $channel = SocialAccount::factory()->youtube()->create([
            'access_token' => 'old-access',
            'token_expires_at' => now()->subMinute(),
        ]);
        $post = Post::factory()->for($channel->user)->withVideo()->create(['title' => null, 'caption' => "Budget speech\nFull text"]);
        $target = $this->targetFor($channel, $post);

        $this->runJob($target);

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertSame('yt-video-1', $target->fresh()->platform_post_id);
        $this->assertSame('new-access', $channel->fresh()->access_token);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'uploadType=resumable')
            && $request['snippet']['title'] === 'Budget speech'
            && $request->hasHeader('Authorization', 'Bearer new-access'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://upload.example.test/session-1'
            && $request->hasHeader('Content-Range', 'bytes 0-'.($chunkSize - 1).'/'.($chunkSize + 1000)));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://upload.example.test/session-1'
            && $request->hasHeader('Content-Range', "bytes {$chunkSize}-".($chunkSize + 999).'/'.($chunkSize + 1000)));
    }

    public function test_youtube_fails_when_video_file_is_missing(): void
    {
        $channel = SocialAccount::factory()->youtube()->create();
        $target = $this->targetFor($channel, Post::factory()->for($channel->user)->withVideo()->create());

        $this->runJob($target);

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertStringContainsString('missing', $target->fresh()->error);
    }

    public function test_tiktok_video_is_initialised_then_status_is_polled(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => ['privacy_level_options' => ['SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'v_pub_1'],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::sequence()
                ->push(['data' => ['status' => 'PROCESSING_DOWNLOAD'], 'error' => ['code' => 'ok']])
                ->push(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7123]], 'error' => ['code' => 'ok']]),
        ]);
        $tiktok = SocialAccount::factory()->tiktok()->create();
        $target = $this->targetFor($tiktok, Post::factory()->for($tiktok->user)->withVideo()->create(['caption' => 'Clip']));

        $this->runJob($target)->assertReleased(30);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'video/init/')
            && $request['post_info']['privacy_level'] === 'SELF_ONLY'
            && $request['source_info']['source'] === 'PULL_FROM_URL');

        $this->runJob($target)->assertReleased(30);
        $this->runJob($target)->assertNotReleased();

        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertSame('7123', $target->fresh()->platform_post_id);
    }

    public function test_tiktok_uses_public_privacy_when_the_app_is_allowed(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => ['privacy_level_options' => ['SELF_ONLY', 'PUBLIC_TO_EVERYONE']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/content/init/' => Http::response([
                'data' => ['publish_id' => 'p_pub_1'],
                'error' => ['code' => 'ok'],
            ]),
        ]);
        $tiktok = SocialAccount::factory()->tiktok()->create();
        $target = $this->targetFor($tiktok, Post::factory()->for($tiktok->user)->withPhoto()->create());

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'content/init/')
            && $request['media_type'] === 'PHOTO'
            && $request['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE');
    }

    public function test_tiktok_failed_processing_fails_the_target(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'FAILED', 'fail_reason' => 'video_pull_failed'],
                'error' => ['code' => 'ok'],
            ]),
        ]);
        $tiktok = SocialAccount::factory()->tiktok()->create();
        $target = $this->targetFor($tiktok, Post::factory()->for($tiktok->user)->withVideo()->create());
        $target->update(['state' => ['publish_id' => 'v_pub_1']]);

        $this->runJob($target);

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertStringContainsString('video_pull_failed', $target->fresh()->error);
    }

    public function test_facebook_uses_custom_caption_location_and_saves_permalink(): void
    {
        Storage::disk('public')->put('media/photo.jpg', 'jpeg-bytes');
        Http::fake([
            'graph.facebook.com/v24.0/111/photos' => Http::response(['id' => 'photo-1', 'post_id' => '111_999']),
            'graph.facebook.com/v24.0/111_999*' => Http::response(['permalink_url' => 'https://www.facebook.com/page/posts/999']),
        ]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($page->user)->withPhoto()->create([
            'caption' => 'Main',
            'options' => ['captions' => ['facebook' => 'Facebook only'], 'location_id' => '555'],
        ]);
        $target = $this->targetFor($page, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => $request->isMultipart()
            && $this->multipartField($request, 'caption') === 'Facebook only'
            && $this->multipartField($request, 'place') === '555');
        $this->assertSame('https://www.facebook.com/page/posts/999', $target->fresh()->permalink);
    }

    public function test_share_target_waits_until_the_main_page_has_posted(): void
    {
        $main = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $sharer = SocialAccount::factory()->for($main->user)->facebook()->create(['platform_account_id' => '222']);
        $post = Post::factory()->for($main->user)->create(['share_from_account_id' => $main->id]);
        $this->targetFor($main, $post);
        $shareTarget = $this->targetFor($sharer, $post);

        $this->runJob($shareTarget)->assertReleased(30);

        Http::assertNothingSent();
        $this->assertSame(TargetStatus::Processing, $shareTarget->fresh()->status);
    }

    public function test_share_target_shares_the_main_post_link_with_its_message(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/222/feed' => Http::response(['id' => '222_7']),
            'graph.facebook.com/v24.0/222_7*' => Http::response(['permalink_url' => 'https://www.facebook.com/222/posts/7']),
        ]);
        $main = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $sharer = SocialAccount::factory()->for($main->user)->facebook()->create(['platform_account_id' => '222', 'access_token' => 'sharer-token']);
        $post = Post::factory()->for($main->user)->create([
            'share_from_account_id' => $main->id,
            'options' => ['share_message' => 'Please read'],
        ]);
        PostTarget::factory()->forPostAndAccount($post, $main)->create([
            'status' => TargetStatus::Published,
            'platform_post_id' => '111_5',
            'permalink' => 'https://www.facebook.com/main/posts/5',
        ]);
        $shareTarget = $this->targetFor($sharer, $post);

        $this->runJob($shareTarget)->assertNotReleased();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/222/feed')
            && $request['link'] === 'https://www.facebook.com/main/posts/5'
            && $request['message'] === 'Please read'
            && $request['access_token'] === 'sharer-token');
        $this->assertSame(TargetStatus::Published, $shareTarget->fresh()->status);
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_share_target_fails_when_the_main_page_failed(): void
    {
        $main = SocialAccount::factory()->facebook()->create();
        $sharer = SocialAccount::factory()->for($main->user)->facebook()->create();
        $post = Post::factory()->for($main->user)->create(['share_from_account_id' => $main->id]);
        PostTarget::factory()->forPostAndAccount($post, $main)->create(['status' => TargetStatus::Failed]);
        $shareTarget = $this->targetFor($sharer, $post);

        $this->runJob($shareTarget);

        Http::assertNothingSent();
        $this->assertSame(TargetStatus::Failed, $shareTarget->fresh()->status);
        $this->assertStringContainsString('Nothing to share', $shareTarget->fresh()->error);
    }

    public function test_main_page_in_share_mode_posts_normally(): void
    {
        Http::fake(['graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_1'])]);
        $main = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($main->user)->create(['caption' => 'Main', 'share_from_account_id' => $main->id]);
        $target = $this->targetFor($main, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v24.0/111/feed' && $request['message'] === 'Main');
        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
    }

    public function test_instagram_sends_tags_collaborators_location_and_saves_permalink(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/ig-1/media' => Http::response(['id' => 'container-1']),
            'graph.facebook.com/v24.0/container-1*' => Http::response(['status_code' => 'FINISHED']),
            'graph.facebook.com/v24.0/ig-1/media_publish' => Http::response(['id' => 'ig-media-9']),
            'graph.facebook.com/v24.0/ig-media-9*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);
        $instagram = SocialAccount::factory()->instagram()->create(['platform_account_id' => 'ig-1']);
        $post = Post::factory()->for($instagram->user)->withPhoto()->create([
            'caption' => 'Main',
            'options' => [
                'captions' => ['instagram' => 'Insta'],
                'location_id' => '555',
                'instagram' => ['user_tags' => ['alice', 'bob'], 'collaborators' => ['partner']],
            ],
        ]);
        $target = $this->targetFor($instagram, $post);

        $this->runJob($target);
        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'ig-1/media')
            && $request['caption'] === 'Insta'
            && $request['location_id'] === '555'
            && json_decode($request['user_tags'], true) === [
                ['username' => 'alice', 'x' => 0.33, 'y' => 0.5],
                ['username' => 'bob', 'x' => 0.67, 'y' => 0.5],
            ]
            && json_decode($request['collaborators'], true) === ['partner']);
        $this->assertSame('https://www.instagram.com/p/abc/', $target->fresh()->permalink);
    }

    public function test_youtube_sends_tags_privacy_and_saves_link(): void
    {
        Storage::disk('public')->put('media/video.mp4', 'small-video');
        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example.test/s']),
            'upload.example.test/s' => Http::response(['id' => 'yt-1'], 201),
        ]);
        $channel = SocialAccount::factory()->youtube()->create();
        $post = Post::factory()->for($channel->user)->withVideo()->create([
            'options' => ['youtube' => ['tags' => ['dhaka'], 'privacy' => 'unlisted']],
        ]);
        $target = $this->targetFor($channel, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'uploadType=resumable')
            && $request['snippet']['tags'] === ['dhaka']
            && $request['status']['privacyStatus'] === 'unlisted');
        $this->assertSame('https://youtu.be/yt-1', $target->fresh()->permalink);
    }

    public function test_tiktok_private_choice_and_interaction_settings_are_sent(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'v_pub_1'],
                'error' => ['code' => 'ok'],
            ]),
        ]);
        $tiktok = SocialAccount::factory()->tiktok()->create();
        $post = Post::factory()->for($tiktok->user)->withVideo()->create([
            'options' => ['tiktok' => ['privacy' => 'private', 'allow_comments' => false, 'allow_duet' => true, 'allow_stitch' => false]],
        ]);
        $target = $this->targetFor($tiktok, $post);

        $this->runJob($target);

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'creator_info/query/'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'video/init/')
            && $request['post_info']['privacy_level'] === 'SELF_ONLY'
            && $request['post_info']['disable_comment'] === true
            && $request['post_info']['disable_duet'] === false
            && $request['post_info']['disable_stitch'] === true);
    }

    public function test_facebook_video_thumbnail_is_sent_as_thumb(): void
    {
        Storage::disk('public')->put('media/video.mp4', 'mp4-bytes');
        Storage::disk('public')->put('thumbnails/cover.jpg', 'jpg-bytes');
        Http::fake(['graph-video.facebook.com/v24.0/111/videos' => Http::response(['id' => 'video-7'])]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($page->user)->withVideo()->create(['thumbnail_path' => 'thumbnails/cover.jpg']);

        $this->runJob($this->targetFor($page, $post));

        Http::assertSent(fn (Request $request) => $this->multipartField($request, 'thumb') === 'jpg-bytes'
            && $this->multipartField($request, 'source') === 'mp4-bytes');
    }

    public function test_instagram_reel_uses_the_thumbnail_as_cover(): void
    {
        Http::fake(['graph.facebook.com/v24.0/ig-1/media' => Http::response(['id' => 'container-1'])]);
        $instagram = SocialAccount::factory()->instagram()->create(['platform_account_id' => 'ig-1']);
        $post = Post::factory()->for($instagram->user)->withVideo()->create(['thumbnail_path' => 'thumbnails/cover.jpg']);

        $this->runJob($this->targetFor($instagram, $post));

        Http::assertSent(fn (Request $request) => $request['media_type'] === 'REELS'
            && $request['cover_url'] === Storage::disk('public')->url('thumbnails/cover.jpg'));
    }

    public function test_youtube_sets_the_thumbnail_and_a_rejection_does_not_fail_the_video(): void
    {
        Storage::disk('public')->put('media/video.mp4', 'video-bytes');
        Storage::disk('public')->put('thumbnails/cover.jpg', 'jpg-bytes');
        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example.test/session-1']),
            'upload.example.test/session-1' => Http::response(['id' => 'yt-video-1'], 201),
            'www.googleapis.com/upload/youtube/v3/thumbnails/set*' => Http::response(['error' => ['message' => 'The authenticated user doesnt have permissions to upload and set custom video thumbnails.']], 403),
        ]);
        $channel = SocialAccount::factory()->youtube()->create(['token_expires_at' => now()->addHour()]);
        $post = Post::factory()->for($channel->user)->withVideo()->create(['thumbnail_path' => 'thumbnails/cover.jpg']);
        $target = $this->targetFor($channel, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'thumbnails/set?videoId=yt-video-1')
            && $request->body() === 'jpg-bytes');
        $this->assertSame(TargetStatus::Published, $target->fresh()->status);
        $this->assertStringContainsString('custom video thumbnails', $target->fresh()->state['thumbnail_error']);
    }

    public function test_facebook_posts_several_photos_as_one_album(): void
    {
        Storage::disk('public')->put('media/a.jpg', 'a');
        Storage::disk('public')->put('media/b.jpg', 'b');
        Http::fake([
            'graph.facebook.com/v24.0/111/photos' => Http::sequence()->push(['id' => 'p1'])->push(['id' => 'p2']),
            'graph.facebook.com/v24.0/111/feed' => Http::response(['id' => '111_77']),
            'graph.facebook.com/v24.0/111_77*' => Http::response([]),
        ]);
        $page = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $post = Post::factory()->for($page->user)->withPhoto()->create(['caption' => 'Album', 'options' => ['gallery' => ['media/a.jpg', 'media/b.jpg']]]);
        $target = $this->targetFor($page, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/111/feed')
            && $request['message'] === 'Album'
            && $request['attached_media[0]'] === '{"media_fbid":"p1"}'
            && $request['attached_media[1]'] === '{"media_fbid":"p2"}');
        $this->assertSame('111_77', $target->fresh()->platform_post_id);
    }

    public function test_instagram_posts_several_photos_as_a_carousel(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/ig-1/media' => Http::sequence()->push(['id' => 'c1'])->push(['id' => 'c2'])->push(['id' => 'carousel-1']),
        ]);
        $instagram = SocialAccount::factory()->instagram()->create(['platform_account_id' => 'ig-1']);
        $post = Post::factory()->for($instagram->user)->withPhoto()->create(['caption' => 'Swipe', 'options' => ['gallery' => ['media/a.jpg', 'media/b.jpg']]]);
        $target = $this->targetFor($instagram, $post);

        $this->runJob($target)->assertReleased(5);

        Http::assertSent(fn (Request $request) => ($request->data()['is_carousel_item'] ?? null) === 'true' && str_ends_with($request->data()['image_url'] ?? '', 'media/b.jpg'));
        Http::assertSent(fn (Request $request) => ($request->data()['media_type'] ?? null) === 'CAROUSEL' && $request['children'] === 'c1,c2' && $request['caption'] === 'Swipe');
        $this->assertSame('carousel-1', $target->fresh()->state['container_id']);
    }

    public function test_tiktok_posts_several_photos_as_a_carousel(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]),
            'open.tiktokapis.com/v2/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'p_1'], 'error' => ['code' => 'ok']]),
        ]);
        $tiktok = SocialAccount::factory()->tiktok()->create();
        $post = Post::factory()->for($tiktok->user)->withPhoto()->create(['options' => ['gallery' => ['media/a.jpg', 'media/b.jpg', 'media/c.jpg']]]);
        $target = $this->targetFor($tiktok, $post);

        $this->runJob($target);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'content/init/') && count($request['source_info']['photo_images']) === 3);
    }
}
