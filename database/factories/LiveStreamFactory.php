<?php

namespace Database\Factories;

use App\Models\LiveStream;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveStream>
 */
class LiveStreamFactory extends Factory
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
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'status' => LiveStream::STATUS_LIVE,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['status' => LiveStream::STATUS_ENDED, 'ended_at' => now()]);
    }
}
