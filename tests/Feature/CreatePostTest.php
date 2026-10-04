<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreatePostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public');
        $this->user = User::factory()->create();
    }

    public function test_create_page_lists_only_the_users_active_accounts(): void
    {
        SocialAccount::factory()->for($this->user)->create(['name' => 'My Active Page']);
        SocialAccount::factory()->for($this->user)->inactive()->create(['name' => 'My Disabled Page']);
        SocialAccount::factory()->create(['name' => 'Someone Else Page']);

        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertOk()
            ->assertSee('My Active Page')
            ->assertDontSee('My Disabled Page')
            ->assertDontSee('Someone Else Page');
    }

    public function test_post_now_creates_targets_and_queues_one_job_per_account_with_a_gap(): void
    {
        $pages = SocialAccount::factory()->for($this->user)->facebook()->count(2)->create();
        $instagram = SocialAccount::factory()->for($this->user)->instagram()->create();

        $this->freezeTime();

        $response = $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [...$pages->pluck('id'), $instagram->id],
            'caption' => 'Hello voters',
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'stagger_seconds' => 60,
        ]);

        $post = Post::sole();
        $response->assertRedirect(route('posts.show', $post));

        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(Post::MEDIA_PHOTO, $post->media_type);
        Storage::disk('public')->assertExists($post->media_path);
        $this->assertSame(3, $post->targets()->where('status', TargetStatus::Pending)->count());

        Queue::assertPushed(PublishPostTarget::class, 3);
        $delays = collect(Queue::pushed(PublishPostTarget::class))
            ->map(fn (PublishPostTarget $job) => (int) now()->diffInSeconds($job->delay))
            ->sort()
            ->values()
            ->all();
        $this->assertSame([0, 60, 120], $delays);
    }

    public function test_scheduled_post_is_saved_in_utc_and_not_queued_yet(): void
    {
        config(['app.display_timezone' => 'Asia/Dhaka']);
        $this->travelTo('2026-10-02 00:00:00');
        $page = SocialAccount::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Rally tomorrow',
            'scheduled_at' => '2026-10-03T18:00',
            'stagger_seconds' => 0,
        ])->assertRedirect();

        $post = Post::sole();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame('2026-10-03 12:00:00', $post->scheduled_at->utc()->toDateTimeString());
        Queue::assertNothingPushed();
    }

    public function test_schedule_time_in_the_past_is_rejected(): void
    {
        $page = SocialAccount::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Old news',
            'scheduled_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('scheduled_at');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_facebook_text_only_post_is_allowed(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Text only update',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('posts', 1);
    }

    public function test_post_without_caption_or_media_is_rejected(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('caption');
    }

    public function test_instagram_requires_media(): void
    {
        $instagram = SocialAccount::factory()->for($this->user)->instagram()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$instagram->id],
            'caption' => 'No photo',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('media');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_instagram_rejects_png_photos(): void
    {
        $instagram = SocialAccount::factory()->for($this->user)->instagram()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$instagram->id],
            'media' => UploadedFile::fake()->image('photo.png'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors(['media' => 'Instagram only accepts JPG photos.']);
    }

    public function test_youtube_accepts_photos_and_gets_a_video_made_from_them(): void
    {
        $channel = SocialAccount::factory()->for($this->user)->youtube()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$channel->id],
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();
    }

    public function test_youtube_accepts_videos(): void
    {
        $channel = SocialAccount::factory()->for($this->user)->youtube()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$channel->id],
            'caption' => 'Speech',
            'media' => UploadedFile::fake()->create('speech.mp4', 500, 'video/mp4'),
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Post::MEDIA_VIDEO, Post::sole()->media_type);
    }

    public function test_tiktok_caption_longer_than_2200_characters_is_rejected(): void
    {
        $tiktok = SocialAccount::factory()->for($this->user)->tiktok()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$tiktok->id],
            'caption' => str_repeat('a', 2201),
            'media' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('caption');
    }

    public function test_cannot_post_to_another_users_account(): void
    {
        $otherAccount = SocialAccount::factory()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$otherAccount->id],
            'caption' => 'Hijack',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('accounts.0');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_cannot_post_to_a_disabled_account(): void
    {
        $page = SocialAccount::factory()->for($this->user)->inactive()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Hello',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('accounts.0');
    }

    public function test_share_mode_saves_the_main_page_and_queues_it_first(): void
    {
        $this->freezeTime();
        [$otherPage, $mainPage] = SocialAccount::factory()->for($this->user)->facebook()->count(2)->create()->all();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$otherPage->id, $mainPage->id],
            'caption' => 'Main announcement',
            'share_mode' => 'share',
            'share_from_account_id' => $mainPage->id,
            'share_message' => 'Please read',
            'stagger_seconds' => 30,
        ])->assertSessionHasNoErrors();

        $post = Post::sole();
        $this->assertSame($mainPage->id, $post->share_from_account_id);
        $this->assertSame('Please read', $post->option('share_message'));

        $firstJob = collect(Queue::pushed(PublishPostTarget::class))
            ->sortBy(fn (PublishPostTarget $job) => $job->delay)
            ->first();
        $this->assertSame($mainPage->id, $firstJob->target->social_account_id);
    }

    public function test_share_mode_requires_main_page_among_selected_facebook_pages(): void
    {
        $pages = SocialAccount::factory()->for($this->user)->facebook()->count(2)->create();
        $notSelected = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => $pages->pluck('id')->all(),
            'caption' => 'Hello',
            'share_mode' => 'share',
            'share_from_account_id' => $notSelected->id,
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('share_from_account_id');
    }

    public function test_share_mode_needs_at_least_two_facebook_pages(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Hello',
            'share_mode' => 'share',
            'share_from_account_id' => $page->id,
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('share_from_account_id');
    }

    public function test_platform_options_are_saved_on_the_post(): void
    {
        $instagram = SocialAccount::factory()->for($this->user)->instagram()->create();
        $channel = SocialAccount::factory()->for($this->user)->youtube()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$instagram->id, $channel->id],
            'caption' => 'Main caption',
            'captions' => ['instagram' => 'Insta caption', 'youtube' => ''],
            'media' => UploadedFile::fake()->create('speech.mp4', 500, 'video/mp4'),
            'location_id' => '108141032537433',
            'instagram_user_tags' => '@first.user, second_user',
            'instagram_collaborators' => 'partner',
            'youtube_tags' => 'dhaka, speech, dhaka',
            'youtube_privacy' => 'unlisted',
            'tiktok_privacy' => 'private',
            'tiktok_allow_comments' => '0',
            'tiktok_allow_duet' => '1',
            'tiktok_allow_stitch' => '1',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $post = Post::sole();
        $this->assertSame('Insta caption', $post->captionFor(Platform::Instagram));
        $this->assertSame('Main caption', $post->captionFor(Platform::YouTube));
        $this->assertSame('108141032537433', $post->option('location_id'));
        $this->assertSame(['first.user', 'second_user'], $post->option('instagram.user_tags'));
        $this->assertSame(['partner'], $post->option('instagram.collaborators'));
        $this->assertSame(['dhaka', 'speech'], $post->option('youtube.tags'));
        $this->assertSame('unlisted', $post->option('youtube.privacy'));
        $this->assertSame('private', $post->option('tiktok.privacy'));
        $this->assertFalse($post->option('tiktok.allow_comments'));
        $this->assertNull($post->share_from_account_id);
    }

    public function test_invalid_instagram_usernames_are_rejected(): void
    {
        $instagram = SocialAccount::factory()->for($this->user)->instagram()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$instagram->id],
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'instagram_user_tags' => 'good_name, bad name!',
            'instagram_collaborators' => 'a, b, c, d',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors(['instagram_user_tags', 'instagram_collaborators']);
    }

    public function test_location_must_be_a_numeric_page_id(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Hello',
            'location_id' => 'Dhaka',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('location_id');
    }

    public function test_custom_instagram_caption_over_2200_characters_is_rejected(): void
    {
        $instagram = SocialAccount::factory()->for($this->user)->instagram()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$instagram->id],
            'caption' => 'Short',
            'captions' => ['instagram' => str_repeat('a', 2201)],
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('caption');
    }

    public function test_link_can_be_shared_to_facebook_pages_without_caption(): void
    {
        $pages = SocialAccount::factory()->for($this->user)->facebook()->count(2)->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => $pages->pluck('id')->all(),
            'link' => 'https://www.facebook.com/someone/posts/12345',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://www.facebook.com/someone/posts/12345', Post::sole()->option('link'));
        Queue::assertPushed(PublishPostTarget::class, 2);
    }

    public function test_link_cannot_be_combined_with_media(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'link' => 'https://example.com/news',
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('link');
    }

    public function test_link_can_only_go_to_facebook_pages(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();
        $tiktok = SocialAccount::factory()->for($this->user)->tiktok()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id, $tiktok->id],
            'link' => 'https://example.com/news',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('link');
    }

    public function test_link_must_be_a_web_address(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'link' => 'javascript:alert(1)',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('link');
    }

    public function test_video_thumbnail_is_saved_with_the_post(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'caption' => 'Speech',
            'media' => UploadedFile::fake()->create('speech.mp4', 500, 'video/mp4'),
            'thumbnail' => UploadedFile::fake()->image('cover.jpg', 1280, 720),
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $post = Post::sole();
        $this->assertTrue($post->hasThumbnail());
        Storage::disk('public')->assertExists($post->thumbnail_path);
    }

    public function test_thumbnail_is_rejected_for_photo_posts_and_when_too_big(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'thumbnail' => UploadedFile::fake()->image('cover.jpg'),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors(['thumbnail' => 'A thumbnail can only be added to a video.']);

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'media' => UploadedFile::fake()->create('speech.mp4', 500, 'video/mp4'),
            'thumbnail' => UploadedFile::fake()->image('cover.jpg')->size(25000),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('thumbnail');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_large_thumbnail_is_shrunk_to_a_jpg_under_two_megabytes(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'media' => UploadedFile::fake()->create('speech.mp4', 500, 'video/mp4'),
            'thumbnail' => UploadedFile::fake()->image('cover.png', 4000, 3000)->size(9000),
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $path = Post::sole()->thumbnail_path;
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertLessThanOrEqual(2 * 1024 * 1024, Storage::disk('public')->size($path));
        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame([1920, 1440], [$width, $height]);
    }

    public function test_several_photos_are_saved_as_an_album(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'media' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
            'music_url' => 'https://example.com/song.mp3',
            'stagger_seconds' => 0,
        ])->assertSessionHasNoErrors();

        $post = Post::sole();
        $this->assertCount(3, $post->photoPaths());
        $this->assertSame($post->photoPaths()[0], $post->media_path);
        $this->assertSame('https://example.com/song.mp3', $post->option('music_url'));
        foreach ($post->photoPaths() as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_photos_and_a_video_cannot_be_mixed(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'media' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->create('v.mp4', 100, 'video/mp4')],
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('media');
    }

    public function test_more_than_ten_photos_are_rejected(): void
    {
        $page = SocialAccount::factory()->for($this->user)->facebook()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$page->id],
            'media' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11)),
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('media');
    }

    public function test_music_must_be_a_video_site_or_audio_file_link(): void
    {
        $channel = SocialAccount::factory()->for($this->user)->youtube()->create();

        $this->actingAs($this->user)->post(route('posts.store'), [
            'accounts' => [$channel->id],
            'media' => UploadedFile::fake()->image('a.jpg'),
            'music_url' => 'https://example.com/page.html',
            'stagger_seconds' => 0,
        ])->assertSessionHasErrors('music_url');
    }
}
