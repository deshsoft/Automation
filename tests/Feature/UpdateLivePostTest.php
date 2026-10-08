<?php

namespace Tests\Feature;

use App\Jobs\UpdateLivePost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateLivePostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.facebook.graph_version' => 'v24.0']);
    }

    public function test_facebook_post_text_is_updated(): void
    {
        Http::fake(['graph.facebook.com/v24.0/111_222' => Http::response(['success' => true])]);
        $target = $this->target('facebook', '111_222', ['caption' => 'New text']);

        app()->call([new UpdateLivePost($target), 'handle']);

        Http::assertSent(fn (Request $request) => $request['message'] === 'New text' && $request['access_token'] === 'page-token');
        $this->assertTrue($target->fresh()->state['live_update']['ok']);
    }

    public function test_facebook_video_description_is_updated(): void
    {
        Http::fake(['graph.facebook.com/v24.0/video-9' => Http::response(['success' => true])]);
        $target = $this->target('facebook', 'video-9', ['caption' => 'Video text', 'media_type' => Post::MEDIA_VIDEO, 'media_path' => 'media/v.mp4']);

        app()->call([new UpdateLivePost($target), 'handle']);

        Http::assertSent(fn (Request $request) => $request['description'] === 'Video text');
    }

    public function test_youtube_video_details_are_updated(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['id' => 'yt-1'])]);
        $target = $this->target('youtube', 'yt-1', ['title' => 'Better title', 'caption' => 'New description', 'options' => ['youtube' => ['tags' => ['dhaka'], 'privacy' => 'unlisted']]]);

        app()->call([new UpdateLivePost($target), 'handle']);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['id'] === 'yt-1'
            && $request['snippet']['title'] === 'Better title'
            && $request['snippet']['description'] === 'New description'
            && $request['snippet']['tags'] === ['dhaka']
            && $request['status']['privacyStatus'] === 'unlisted');
        $this->assertTrue($target->fresh()->state['live_update']['ok']);
    }

    public function test_old_youtube_connection_is_told_to_reconnect(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['error' => ['message' => 'Request had insufficient authentication scopes.']], 403)]);
        $target = $this->target('youtube', 'yt-1');

        app()->call([new UpdateLivePost($target), 'handle']);

        $this->assertFalse($target->fresh()->state['live_update']['ok']);
        $this->assertStringContainsString('Reconnect it once on the Accounts page', $target->fresh()->state['live_update']['message']);
    }

    public function test_edit_result_is_shown_on_the_post_page(): void
    {
        $target = $this->target('facebook', '1_2');
        $target->rememberState(['live_update' => ['ok' => false, 'message' => 'Facebook edit: Permissions error', 'at' => now()->toIso8601String()]]);

        $this->actingAs($target->post->user)->get(route('posts.show', $target->post))->assertSee('Edit failed: Facebook edit: Permissions error');
    }

    /**
     * @param  array<string, mixed>  $postAttributes
     */
    private function target(string $platform, string $platformPostId, array $postAttributes = []): PostTarget
    {
        $account = SocialAccount::factory()->{$platform}()->create(['access_token' => 'page-token']);
        $post = Post::factory()->for($account->user)->create(['status' => 'published', ...$postAttributes]);

        return PostTarget::factory()->forPostAndAccount($post, $account)->create(['status' => 'published', 'platform_post_id' => $platformPostId]);
    }
}
