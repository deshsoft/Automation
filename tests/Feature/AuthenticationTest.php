<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('posts.index'))->assertRedirect(route('login'));
    }

    public function test_guests_see_the_public_home_page(): void
    {
        config(['app.contact_email' => 'owner@example.com']);

        $this->get('/')
            ->assertOk()
            ->assertViewIs('home')
            ->assertSee(route('privacy'))
            ->assertSee(route('terms'))
            ->assertSee('owner@example.com')
            ->assertDontSee('Publishing activity');
    }

    public function test_user_can_log_in_with_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_log_in_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_create_user_command_creates_a_login(): void
    {
        $this->artisan('app:create-user', ['--name' => 'Admin', '--email' => 'admin@example.com', '--password' => 'long-password'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
    }

    public function test_privacy_policy_and_terms_are_public(): void
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Privacy Policy')->assertSee('YouTube API Services');
        $this->get(route('terms'))->assertOk()->assertSee('Terms of Service');
    }
}
