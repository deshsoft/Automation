<?php

namespace Database\Factories;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTarget>
 */
class PostTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'status' => TargetStatus::Pending,
            'platform_post_id' => null,
            'state' => null,
            'error' => null,
        ];
    }

    public function forPostAndAccount(Post $post, SocialAccount $account): static
    {
        return $this->state(fn () => [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
        ]);
    }
}
