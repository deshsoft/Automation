<?php

namespace Tests\Feature;

use App\Models\LiveStream;
use App\Models\LiveStreamTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.facebook.graph_version' => 'v24.0']);
    }

    public function test_go_live_page_lists_only_own_active_facebook_pages(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->facebook()->create(['name' => 'My FB Page']);
        SocialAccount::factory()->for($user)->instagram()->create(['name' => 'My Instagram']);
        SocialAccount::factory()->facebook()->create(['name' => 'Other Users Page']);

        $this->actingAs($user)->get(route('live.index'))
            ->assertOk()
            ->assertSee('My FB Page')
            ->assertDontSee('My Instagram')
            ->assertDontSee('Other Users Page');
    }

    public function test_creating_a_live_creates_live_videos_on_each_page_and_shows_keys(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/111/live_videos' => Http::response([
                'id' => 'live-1',
                'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/FB-KEY-111',
            ]),
            'graph.facebook.com/v24.0/live-1*' => Http::response(['permalink_url' => '/111/videos/live-1/']),
            'graph.facebook.com/v24.0/222/live_videos' => Http::response(['error' => ['message' => 'Page cannot go live']], 400),
        ]);
        $first = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111', 'access_token' => 'token-111']);
        $second = SocialAccount::factory()->for($first->user)->facebook()->create(['platform_account_id' => '222']);

        $response = $this->actingAs($first->user)->post(route('live.store'), [
            'title' => 'Town hall',
            'description' => 'Live from Mirpur',
            'accounts' => [$first->id, $second->id],
        ]);

        $liveStream = LiveStream::sole();
        $response->assertRedirect(route('live.show', $liveStream));
        $this->assertTrue($liveStream->isLive());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/111/live_videos')
            && $request['status'] === 'LIVE_NOW'
            && $request['title'] === 'Town hall'
            && $request['access_token'] === 'token-111');

        $ready = $liveStream->targets()->where('social_account_id', $first->id)->sole();
        $this->assertSame(LiveStreamTarget::STATUS_READY, $ready->status);
        $this->assertSame('rtmps://live-api-s.facebook.com:443/rtmp/', $ready->server());
        $this->assertSame('FB-KEY-111', $ready->streamKey());
        $this->assertSame('https://www.facebook.com/111/videos/live-1/', $ready->permalink);

        $failed = $liveStream->targets()->where('social_account_id', $second->id)->sole();
        $this->assertSame(LiveStreamTarget::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('Page cannot go live', $failed->error);

        $this->actingAs($first->user)->get(route('live.show', $liveStream))
            ->assertOk()
            ->assertSee('rtmps://live-api-s.facebook.com:443/rtmp/')
            ->assertSee('FB-KEY-111')
            ->assertSee('Page cannot go live');
    }

    public function test_stream_keys_are_encrypted_in_the_database(): void
    {
        $target = LiveStreamTarget::factory()->create(['stream_url' => 'rtmps://server/rtmp/SECRET-KEY']);

        $stored = DB::table('live_stream_targets')->where('id', $target->id)->value('stream_url');

        $this->assertStringNotContainsString('SECRET-KEY', $stored);
    }

    public function test_live_is_marked_ended_when_no_page_could_go_live(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Missing permission publish_video']], 403)]);
        $page = SocialAccount::factory()->facebook()->create();

        $this->actingAs($page->user)->post(route('live.store'), ['title' => 'Test', 'accounts' => [$page->id]])
            ->assertSessionHas('error');

        $this->assertFalse(LiveStream::sole()->isLive());
    }

    public function test_cannot_go_live_on_instagram_or_another_users_page(): void
    {
        $user = User::factory()->create();
        $instagram = SocialAccount::factory()->for($user)->instagram()->create();
        $otherPage = SocialAccount::factory()->facebook()->create();

        $this->actingAs($user)->post(route('live.store'), ['title' => 'Test', 'accounts' => [$instagram->id, $otherPage->id]])
            ->assertSessionHasErrors(['accounts.0', 'accounts.1']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('live_streams', 0);
    }

    public function test_title_is_required(): void
    {
        $page = SocialAccount::factory()->facebook()->create();

        $this->actingAs($page->user)->post(route('live.store'), ['accounts' => [$page->id]])
            ->assertSessionHasErrors('title');
    }

    public function test_ending_a_live_ends_it_on_every_ready_page(): void
    {
        Http::fake(['graph.facebook.com/v24.0/live-1' => Http::response(['success' => true])]);
        $liveStream = LiveStream::factory()->create();
        $page = SocialAccount::factory()->for($liveStream->user)->facebook()->create(['access_token' => 'page-token']);
        $ready = LiveStreamTarget::factory()->for($liveStream)->create(['social_account_id' => $page->id, 'platform_live_id' => 'live-1']);
        $failedPage = SocialAccount::factory()->for($liveStream->user)->facebook()->create();
        LiveStreamTarget::factory()->for($liveStream)->create(['social_account_id' => $failedPage->id, 'status' => LiveStreamTarget::STATUS_FAILED]);

        $this->actingAs($liveStream->user)->post(route('live.end', $liveStream))->assertSessionHas('success');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['end_live_video'] === 'true' && $request['access_token'] === 'page-token');
        $this->assertSame(LiveStreamTarget::STATUS_ENDED, $ready->fresh()->status);
        $this->assertFalse($liveStream->fresh()->isLive());
    }

    public function test_ended_live_hides_stream_keys(): void
    {
        $liveStream = LiveStream::factory()->ended()->create();
        LiveStreamTarget::factory()->for($liveStream)->create([
            'social_account_id' => SocialAccount::factory()->for($liveStream->user)->facebook(),
            'status' => LiveStreamTarget::STATUS_ENDED,
            'stream_url' => 'rtmps://server/rtmp/OLD-KEY',
        ]);

        $this->actingAs($liveStream->user)->get(route('live.show', $liveStream))
            ->assertOk()
            ->assertDontSee('OLD-KEY');
    }

    public function test_cannot_view_or_end_another_users_live(): void
    {
        $liveStream = LiveStream::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('live.show', $liveStream))->assertForbidden();
        $this->actingAs($intruder)->post(route('live.end', $liveStream))->assertForbidden();

        $this->assertTrue($liveStream->fresh()->isLive());
    }

    public function test_share_mode_goes_live_only_on_the_main_page(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/111/live_videos' => Http::response([
                'id' => 'live-1',
                'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/KEY-1',
            ]),
            'graph.facebook.com/v24.0/live-1*' => Http::response(['permalink_url' => 'https://www.facebook.com/111/videos/live-1/']),
        ]);
        $main = SocialAccount::factory()->facebook()->create(['platform_account_id' => '111']);
        $sharer = SocialAccount::factory()->for($main->user)->facebook()->create(['platform_account_id' => '222']);

        $this->actingAs($main->user)->post(route('live.store'), [
            'title' => 'Rally',
            'accounts' => [$main->id, $sharer->id],
            'mode' => 'share',
            'main_account_id' => $main->id,
            'share_message' => 'Watch live now',
        ])->assertSessionHasNoErrors();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/222/'));

        $liveStream = LiveStream::sole();
        $this->assertSame('Watch live now', $liveStream->share_message);
        $this->assertSame(LiveStreamTarget::ROLE_LIVE, $liveStream->targets()->where('social_account_id', $main->id)->value('role'));
        $sharerTarget = $liveStream->targets()->where('social_account_id', $sharer->id)->sole();
        $this->assertSame(LiveStreamTarget::ROLE_SHARE, $sharerTarget->role);
        $this->assertSame(LiveStreamTarget::STATUS_WAITING, $sharerTarget->status);

        $this->actingAs($main->user)->get(route('live.show', $liveStream))
            ->assertSee('Share to other Pages (1)')
            ->assertSee('KEY-1');
    }

    public function test_share_mode_requires_the_main_page_among_two_or_more_selected_pages(): void
    {
        $user = User::factory()->create();
        [$first, $second] = SocialAccount::factory()->for($user)->facebook()->count(2)->create()->all();
        $notSelected = SocialAccount::factory()->for($user)->facebook()->create();

        $this->actingAs($user)->post(route('live.store'), [
            'title' => 'Rally', 'accounts' => [$first->id, $second->id], 'mode' => 'share', 'main_account_id' => $notSelected->id,
        ])->assertSessionHasErrors('main_account_id');

        $this->actingAs($user)->post(route('live.store'), [
            'title' => 'Rally', 'accounts' => [$first->id], 'mode' => 'share', 'main_account_id' => $first->id,
        ])->assertSessionHasErrors('main_account_id');

        Http::assertNothingSent();
        $this->assertDatabaseCount('live_streams', 0);
    }

    public function test_sharing_posts_the_main_live_link_on_the_other_pages(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/222/feed' => Http::response(['id' => '222_9']),
            'graph.facebook.com/v24.0/333/feed' => Http::response(['error' => ['message' => 'Page restricted']], 403),
            'graph.facebook.com/v24.0/222_9*' => Http::response(['permalink_url' => 'https://www.facebook.com/222/posts/9']),
        ]);
        $liveStream = LiveStream::factory()->create(['share_message' => 'Watch live now']);
        $main = SocialAccount::factory()->for($liveStream->user)->facebook()->create();
        LiveStreamTarget::factory()->for($liveStream)->create([
            'social_account_id' => $main->id,
            'permalink' => 'https://www.facebook.com/111/videos/live-1/',
        ]);
        $sharer = SocialAccount::factory()->for($liveStream->user)->facebook()->create(['platform_account_id' => '222', 'access_token' => 'token-222']);
        $restricted = SocialAccount::factory()->for($liveStream->user)->facebook()->create(['platform_account_id' => '333']);
        $sharerTarget = LiveStreamTarget::factory()->for($liveStream)->sharer()->create(['social_account_id' => $sharer->id]);
        $restrictedTarget = LiveStreamTarget::factory()->for($liveStream)->sharer()->create(['social_account_id' => $restricted->id]);

        $this->actingAs($liveStream->user)->post(route('live.share', $liveStream))
            ->assertSessionHas('error', 'Shared on 1 Page(s), 1 failed. Click the button again to retry.');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/222/feed')
            && $request['link'] === 'https://www.facebook.com/111/videos/live-1/'
            && $request['message'] === 'Watch live now'
            && $request['access_token'] === 'token-222');
        $this->assertSame(LiveStreamTarget::STATUS_SHARED, $sharerTarget->fresh()->status);
        $this->assertSame('https://www.facebook.com/222/posts/9', $sharerTarget->fresh()->permalink);
        $this->assertSame(LiveStreamTarget::STATUS_FAILED, $restrictedTarget->fresh()->status);
        $this->assertStringContainsString('Page restricted', $restrictedTarget->fresh()->error);
    }

    public function test_sharing_is_refused_when_the_main_page_is_not_live(): void
    {
        $liveStream = LiveStream::factory()->create();
        LiveStreamTarget::factory()->for($liveStream)->create([
            'social_account_id' => SocialAccount::factory()->for($liveStream->user)->facebook(),
            'status' => LiveStreamTarget::STATUS_FAILED,
        ]);
        LiveStreamTarget::factory()->for($liveStream)->sharer()->create([
            'social_account_id' => SocialAccount::factory()->for($liveStream->user)->facebook(),
        ]);

        $this->actingAs($liveStream->user)->post(route('live.share', $liveStream))->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_ending_a_shared_live_only_ends_the_main_broadcast(): void
    {
        Http::fake(['graph.facebook.com/v24.0/live-1' => Http::response(['success' => true])]);
        $liveStream = LiveStream::factory()->create();
        LiveStreamTarget::factory()->for($liveStream)->create([
            'social_account_id' => SocialAccount::factory()->for($liveStream->user)->facebook(),
            'platform_live_id' => 'live-1',
        ]);
        $shared = LiveStreamTarget::factory()->for($liveStream)->sharer()->create([
            'social_account_id' => SocialAccount::factory()->for($liveStream->user)->facebook(),
            'status' => LiveStreamTarget::STATUS_SHARED,
        ]);

        $this->actingAs($liveStream->user)->post(route('live.end', $liveStream))->assertSessionHas('success');

        Http::assertSentCount(1);
        $this->assertSame(LiveStreamTarget::STATUS_SHARED, $shared->fresh()->status);
    }

    public function test_cannot_share_another_users_live(): void
    {
        $liveStream = LiveStream::factory()->create();

        $this->actingAs(User::factory()->create())->post(route('live.share', $liveStream))->assertForbidden();
    }
}
