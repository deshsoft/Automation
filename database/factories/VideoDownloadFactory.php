<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoDownload>
 */
class VideoDownloadFactory extends Factory
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
            'url' => 'https://www.youtube.com/watch?v='.fake()->regexify('[A-Za-z0-9_-]{11}'),
            'source' => VideoDownload::SOURCE_YOUTUBE,
            'quality' => '720',
            'status' => VideoDownload::STATUS_QUEUED,
        ];
    }

    public function facebook(): static
    {
        return $this->state(fn () => [
            'url' => 'https://www.facebook.com/watch/?v='.fake()->numerify('##########'),
            'source' => VideoDownload::SOURCE_FACEBOOK,
        ]);
    }

    public function completed(string $filePath = 'downloads/1/video.mp4'): static
    {
        return $this->state(fn () => [
            'status' => VideoDownload::STATUS_COMPLETED,
            'title' => fake()->sentence(4),
            'file_path' => $filePath,
            'file_size' => 1_048_576,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => VideoDownload::STATUS_FAILED,
            'error' => 'ERROR: Video unavailable',
        ]);
    }
}
