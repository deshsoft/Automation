<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_own_accounts(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create(['name' => 'My Page']);
        SocialAccount::factory()->create(['name' => 'Their Page']);

        $this->actingAs($user)->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('My Page')
            ->assertDontSee('Their Page');
    }

    public function test_account_can_be_disabled(): void
    {
        $account = SocialAccount::factory()->create();

        $this->actingAs($account->user)->patch(route('accounts.update', $account), ['is_active' => 0]);

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_account_can_be_removed(): void
    {
        $account = SocialAccount::factory()->create();

        $this->actingAs($account->user)->delete(route('accounts.destroy', $account));

        $this->assertModelMissing($account);
    }

    public function test_cannot_change_another_users_account(): void
    {
        $account = SocialAccount::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patch(route('accounts.update', $account), ['is_active' => 0])->assertForbidden();
        $this->actingAs($intruder)->delete(route('accounts.destroy', $account))->assertForbidden();

        $this->assertTrue($account->fresh()->is_active);
    }
}
