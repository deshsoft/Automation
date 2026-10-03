<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-system-token-that-is-long-enough-123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.system_token' => self::TOKEN]);
        File::delete(SystemDiagnostics::ipv4FlagPath());
    }

    protected function tearDown(): void
    {
        File::delete(SystemDiagnostics::ipv4FlagPath());

        parent::tearDown();
    }

    private function unlocked(): static
    {
        return $this->withSession(['system_unlocked_until' => now()->addMinutes(10)->timestamp]);
    }

    public function test_page_is_switched_off_without_a_long_token(): void
    {
        config(['app.system_token' => 'short']);

        $this->get(route('system.show'))->assertOk()->assertSee('System page is switched off');
        $this->post(route('system.unlock'), ['token' => 'short'])->assertNotFound();
    }

    public function test_page_asks_for_the_token_first(): void
    {
        $this->get(route('system.show'))->assertOk()->assertSee('Access token')->assertDontSee('Server checks');
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->post(route('system.unlock'), ['token' => 'wrong-token'])->assertSessionHasErrors('token');

        $this->get(route('system.show'))->assertDontSee('Server checks');
    }

    public function test_correct_token_unlocks_the_page_without_logging_in(): void
    {
        $this->post(route('system.unlock'), ['token' => self::TOKEN])->assertRedirect(route('system.show'));

        $this->get(route('system.show'))->assertOk()->assertSee('Server checks')->assertSee('artisan schedule:run');
    }

    public function test_unlock_expires(): void
    {
        $this->withSession(['system_unlocked_until' => now()->subMinute()->timestamp])
            ->get(route('system.show'))
            ->assertSee('Access token');
    }

    public function test_actions_require_the_page_to_be_unlocked(): void
    {
        $this->post(route('system.run'), ['action' => 'clear-cache'])->assertForbidden();
        $this->post(route('system.ipv4'), ['enabled' => 1])->assertForbidden();
        $this->post(route('system.users'), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123'])->assertForbidden();

        $this->assertFalse(SystemDiagnostics::forcesIpv4());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_allowed_action_runs_and_shows_its_output(): void
    {
        $this->unlocked()->post(route('system.run'), ['action' => 'publish-due'])
            ->assertRedirect(route('system.show'))
            ->assertSessionHas('success')
            ->assertSessionHas('command_output', fn (string $output) => str_contains($output, 'Started 0 scheduled post(s).'));
    }

    public function test_downloader_install_is_offered(): void
    {
        $this->unlocked()->get(route('system.show'))->assertSee('Install video downloader (1/3: yt-dlp)')
            ->assertSee('Video downloader (yt-dlp)');
    }

    public function test_only_listed_actions_can_run(): void
    {
        $this->unlocked()->post(route('system.run'), ['action' => 'db:wipe'])->assertSessionHasErrors('action');
    }

    public function test_force_ipv4_can_be_switched_on_and_off(): void
    {
        $this->unlocked()->post(route('system.ipv4'), ['enabled' => 1])->assertSessionHas('success');
        $this->assertTrue(SystemDiagnostics::forcesIpv4());

        $this->unlocked()->post(route('system.ipv4'), ['enabled' => 0]);
        $this->assertFalse(SystemDiagnostics::forcesIpv4());
    }

    public function test_network_test_recommends_ipv4_when_only_ipv4_works(): void
    {
        $call = 0;
        Http::fake(function () use (&$call) {
            // Each API is tried normally first, then with IPv4 only.
            return $call++ % 2 === 0 ? Http::failedConnection() : Http::response('', 404);
        });

        $this->unlocked()->get(route('system.show', ['network' => 1]))
            ->assertOk()
            ->assertSee('IPv6 is broken, but IPv4 works');
    }

    public function test_network_test_reports_blocked_hosting(): void
    {
        Http::fake(fn () => Http::failedConnection());

        $this->unlocked()->get(route('system.show', ['network' => 1]))
            ->assertSee('The hosting company blocks these connections');
    }

    public function test_a_login_can_be_created(): void
    {
        $this->unlocked()->post(route('system.users'), [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'long-password',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('long-password', User::sole()->password));
    }

    public function test_sidebar_links_to_the_system_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee(route('system.show'))
            ->assertSee('Admin');
    }
}
