<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => Platform::Facebook,
            'platform_account_id' => (string) fake()->unique()->numberBetween(100000, 999999999),
            'name' => fake()->company(),
            'username' => fake()->userName(),
            'access_token' => 'token-'.fake()->sha1(),
            'refresh_token' => null,
            'token_expires_at' => null,
            'meta' => null,
            'is_active' => true,
        ];
    }

    public function facebook(): static
    {
        return $this->state(fn () => ['platform' => Platform::Facebook]);
    }

    public function instagram(): static
    {
        return $this->state(fn () => ['platform' => Platform::Instagram]);
    }

    public function youtube(): static
    {
        return $this->state(fn () => [
            'platform' => Platform::YouTube,
            'refresh_token' => 'refresh-'.fake()->sha1(),
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function tiktok(): static
    {
        return $this->state(fn () => [
            'platform' => Platform::TikTok,
            'refresh_token' => 'refresh-'.fake()->sha1(),
            'token_expires_at' => now()->addDay(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
