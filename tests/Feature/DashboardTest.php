<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_dashboard_counts_only_the_users_own_deliveries(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $account = SocialAccount::factory()->create();
        $post = Post::factory()->for($account->user)->create(['status' => PostStatus::PartiallyFailed, 'caption' => 'Ward meeting today']);
        PostTarget::factory()->forPostAndAccount($post, $account)->create(['status' => TargetStatus::Published, 'published_at' => now()]);
        $secondAccount = SocialAccount::factory()->for($account->user)->create();
        PostTarget::factory()->forPostAndAccount($post, $secondAccount)->create(['status' => TargetStatus::Failed]);

        $otherAccount = SocialAccount::factory()->create();
        $otherPost = Post::factory()->for($otherAccount->user)->create();
        PostTarget::factory()->forPostAndAccount($otherPost, $otherAccount)->create(['status' => TargetStatus::Published, 'published_at' => now()]);

        $response = $this->actingAs($account->user)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('stats')['published']);
        $this->assertSame(1, $response->viewData('stats')['failed']);
        $this->assertSame(50, $response->viewData('stats')['success_rate']);
        $this->assertSame(2, $response->viewData('stats')['accounts']);

        $today = last($response->viewData('chart'));
        $this->assertSame(['published' => 1, 'failed' => 1], ['published' => $today['published'], 'failed' => $today['failed']]);
        $this->assertCount(14, $response->viewData('chart'));

        $response->assertSee('Ward meeting today')->assertSee('1 account(s) failed');
    }

    public function test_dashboard_lists_upcoming_scheduled_posts(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->scheduled(now()->addDay())->create(['caption' => 'Tomorrow rally']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('Tomorrow rally')
            ->assertViewHas('stats', fn (array $stats) => $stats['scheduled'] === 1);
    }

    public function test_posts_can_be_filtered_by_status_and_searched(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->create(['status' => PostStatus::Published, 'caption' => 'Published speech']);
        Post::factory()->for($user)->create(['status' => PostStatus::PartiallyFailed, 'caption' => 'Broken upload']);
        Post::factory()->for($user)->create(['status' => PostStatus::Failed, 'caption' => 'Another failure']);

        $this->actingAs($user)->get(route('posts.index', ['status' => 'failed']))
            ->assertSee('Broken upload')
            ->assertSee('Another failure')
            ->assertDontSee('Published speech');

        $this->actingAs($user)->get(route('posts.index', ['search' => 'speech']))
            ->assertSee('Published speech')
            ->assertDontSee('Broken upload');
    }
}
