<?php

namespace Database\Factories;

use App\Models\LiveStream;
use App\Models\LiveStreamTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveStreamTarget>
 */
class LiveStreamTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'live_stream_id' => LiveStream::factory(),
            'social_account_id' => SocialAccount::factory(),
            'role' => LiveStreamTarget::ROLE_LIVE,
            'status' => LiveStreamTarget::STATUS_READY,
            'platform_live_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/FB-'.fake()->bothify('####-####'),
        ];
    }

    public function sharer(): static
    {
        return $this->state(fn () => [
            'role' => LiveStreamTarget::ROLE_SHARE,
            'status' => LiveStreamTarget::STATUS_WAITING,
            'platform_live_id' => null,
            'stream_url' => null,
        ]);
    }
}
