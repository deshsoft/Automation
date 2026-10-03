<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
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
            'title' => null,
            'caption' => fake()->paragraph(),
            'media_path' => null,
            'media_type' => null,
            'media_mime' => null,
            'stagger_seconds' => 0,
            'status' => PostStatus::Publishing,
            'scheduled_at' => null,
        ];
    }

    public function withPhoto(): static
    {
        return $this->state(fn () => [
            'media_path' => 'media/photo.jpg',
            'media_type' => Post::MEDIA_PHOTO,
            'media_mime' => 'image/jpeg',
        ]);
    }

    public function withVideo(): static
    {
        return $this->state(fn () => [
            'media_path' => 'media/video.mp4',
            'media_type' => Post::MEDIA_VIDEO,
            'media_mime' => 'video/mp4',
        ]);
    }

    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }
}
