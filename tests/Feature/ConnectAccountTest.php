<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config([
            'services.facebook.client_id' => 'fb-app',
            'services.facebook.client_secret' => 'fb-secret',
            'services.facebook.graph_version' => 'v24.0',
            'services.google.client_id' => 'google-app',
            'services.google.client_secret' => 'google-secret',
            'services.tiktok.client_key' => 'tiktok-app',
            'services.tiktok.client_secret' => 'tiktok-secret',
        ]);
    }

    public function test_redirect_sends_user_to_facebook_with_state_and_scopes(): void
    {
        $response = $this->actingAs($this->user)->get(route('connect.redirect', 'meta'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v24.0/dialog/oauth?', $location);
        $this->assertStringContainsString('instagram_content_publish', urldecode($location));
        $this->assertStringContainsString('state='.session('oauth_state.meta'), $location);
    }

    public function test_redirect_uses_configuration_id_for_login_for_business_apps(): void
    {
        config(['services.facebook.config_id' => 'config-42']);

        $location = $this->actingAs($this->user)->get(route('connect.redirect', 'meta'))->headers->get('Location');

        $this->assertStringContainsString('config_id=config-42', $location);
        $this->assertStringNotContainsString('scope=', $location);
    }

    public function test_callback_address_always_uses_the_app_url(): void
    {
        config(['app.url' => 'https://publisher.example.com']);

        $location = $this->actingAs($this->user)
            ->get('http://127.0.0.1:8090/connect/google')
            ->headers->get('Location');

        $this->assertStringContainsString('redirect_uri='.urlencode('https://publisher.example.com/connect/google/callback'), $location);
    }

    public function test_redirect_explains_missing_app_keys(): void
    {
        config(['services.tiktok.client_key' => null]);

        $this->actingAs($this->user)->get(route('connect.redirect', 'tiktok'))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('error');
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->actingAs($this->user)->get('/connect/myspace')->assertNotFound();
    }

    public function test_callback_with_wrong_state_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->withSession(['oauth_state.meta' => 'expected'])
            ->get(route('connect.callback', ['provider' => 'meta', 'code' => 'abc', 'state' => 'forged']))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_callback_shows_error_when_user_denies_access(): void
    {
        $this->actingAs($this->user)
            ->withSession(['oauth_state.meta' => 'state-1'])
            ->get(route('connect.callback', ['provider' => 'meta', 'state' => 'state-1', 'error' => 'access_denied', 'error_description' => 'Permissions error']))
            ->assertSessionHas('error', 'Facebook / Instagram: Permissions error');
    }

    public function test_meta_callback_saves_pages_and_linked_instagram_accounts(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short-lived'])
                ->push(['access_token' => 'long-lived']),
            'graph.facebook.com/v24.0/me/accounts*' => Http::response(['data' => [
                [
                    'id' => '111',
                    'name' => 'Mirpur Page',
                    'access_token' => 'page-token-111',
                    'instagram_business_account' => ['id' => 'ig-1', 'username' => 'mirpur_ig'],
                ],
                ['id' => '222', 'name' => 'Second Page', 'access_token' => 'page-token-222'],
            ]]),
        ]);

        $this->actingAs($this->user)
            ->withSession(['oauth_state.meta' => 'state-1'])
            ->get(route('connect.callback', ['provider' => 'meta', 'code' => 'code-1', 'state' => 'state-1']))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('success', 'Facebook / Instagram: 3 account(s) connected.');

        $page = $this->user->socialAccounts()->where('platform_account_id', '111')->sole();
        $this->assertSame(Platform::Facebook, $page->platform);
        $this->assertSame('page-token-111', $page->access_token);

        $instagram = $this->user->socialAccounts()->where('platform', Platform::Instagram)->sole();
        $this->assertSame('mirpur_ig', $instagram->username);
        $this->assertSame('page-token-111', $instagram->access_token);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fb_exchange_token=short-lived'));
    }

    public function test_tokens_are_encrypted_in_the_database(): void
    {
        $account = SocialAccount::factory()->create(['access_token' => 'plain-secret-token']);

        $stored = DB::table('social_accounts')->where('id', $account->id)->value('access_token');

        $this->assertNotSame('plain-secret-token', $stored);
        $this->assertSame('plain-secret-token', $account->fresh()->access_token);
    }

    public function test_reconnecting_updates_the_existing_account_instead_of_duplicating(): void
    {
        SocialAccount::factory()->for($this->user)->facebook()->create(['platform_account_id' => '111', 'access_token' => 'old']);
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response(['access_token' => 'token']),
            'graph.facebook.com/v24.0/me/accounts*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Renamed Page', 'access_token' => 'new'],
            ]]),
        ]);

        $this->actingAs($this->user)
            ->withSession(['oauth_state.meta' => 'state-1'])
            ->get(route('connect.callback', ['provider' => 'meta', 'code' => 'code-1', 'state' => 'state-1']));

        $account = $this->user->socialAccounts()->sole();
        $this->assertSame('Renamed Page', $account->name);
        $this->assertSame('new', $account->access_token);
    }

    public function test_google_callback_saves_youtube_channel_with_refresh_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-access', 'refresh_token' => 'g-refresh', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [
                ['id' => 'UC123', 'snippet' => ['title' => 'My Channel', 'customUrl' => '@mychannel']],
            ]]),
        ]);

        $this->actingAs($this->user)
            ->withSession(['oauth_state.google' => 'state-1'])
            ->get(route('connect.callback', ['provider' => 'google', 'code' => 'code-1', 'state' => 'state-1']))
            ->assertSessionHas('success');

        $channel = $this->user->socialAccounts()->sole();
        $this->assertSame(Platform::YouTube, $channel->platform);
        $this->assertSame('g-refresh', $channel->refresh_token);
        $this->assertTrue($channel->token_expires_at->isFuture());
    }

    public function test_google_account_without_channel_shows_error(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-access', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => []]),
        ]);

        $this->actingAs($this->user)
            ->withSession(['oauth_state.google' => 'state-1'])
            ->get(route('connect.callback', ['provider' => 'google', 'code' => 'code-1', 'state' => 'state-1']))
            ->assertSessionHas('error', 'This Google account has no YouTube channel.');
    }

    public function test_tiktok_callback_saves_account(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 't-access', 'refresh_token' => 't-refresh', 'expires_in' => 86400, 'open_id' => 'open-1',
            ]),
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['display_name' => 'Tok User']]]),
        ]);

        $this->actingAs($this->user)
            ->withSession(['oauth_state.tiktok' => 'state-1'])
            ->get(route('connect.callback', ['provider' => 'tiktok', 'code' => 'code-1', 'state' => 'state-1']))
            ->assertSessionHas('success');

        $account = $this->user->socialAccounts()->sole();
        $this->assertSame('Tok User', $account->name);
        $this->assertSame('open-1', $account->platform_account_id);
    }
}
