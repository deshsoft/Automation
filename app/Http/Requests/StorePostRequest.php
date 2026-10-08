<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\VideoDownload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
    public const SHARE_MODE_SEPARATE = 'separate';

    public const SHARE_MODE_SHARE = 'share';

    /**
     * @var Collection<int, SocialAccount>|null
     */
    private ?Collection $selectedAccounts = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    /**
     * "From a link" posts send one source_url. Turn it into what each platform
     * needs: a shared link for Facebook (when chosen) and a downloaded video
     * for everything else (YouTube and TikTok cannot share links).
     */
    protected function prepareForValidation(): void
    {
        $sourceUrl = trim((string) $this->input('source_url'));

        if ($sourceUrl === '') {
            return;
        }

        $platforms = $this->selectedAccounts()->pluck('platform')->unique();
        $hasFacebook = $platforms->contains(Platform::Facebook);
        $facebookShares = $hasFacebook && $this->input('facebook_link_mode', 'share') !== 'upload';
        $needsDownload = $platforms->contains(fn (Platform $platform) => $platform !== Platform::Facebook)
            || ($hasFacebook && ! $facebookShares);

        $this->merge([
            'link' => $facebookShares ? $sourceUrl : null,
            'import_url' => $needsDownload ? $sourceUrl : null,
        ]);
    }

    public const MAX_PHOTOS = 10;

    /**
     * The uploaded photo(s) or video, always as a list.
     *
     * @return list<UploadedFile>
     */
    public function mediaFiles(): array
    {
        return array_values(array_filter(Arr::wrap($this->file('media'))));
    }

    /**
     * @return list<string>
     */
    private function mediaFileRules(): array
    {
        return [
            'file',
            'mimetypes:image/jpeg,image/png,video/mp4,video/quicktime',
            'max:'.((int) config('filesystems.max_upload_mb', 1024) * 1024),
        ];
    }

    public function rules(): array
    {
        return [
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => [
                'integer',
                'distinct',
                Rule::exists('social_accounts', 'id')
                    ->where('user_id', $this->user()->id)
                    ->where('is_active', true),
            ],
            'title' => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'link' => ['nullable', 'url:http,https', 'max:2000'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'facebook_link_mode' => ['nullable', Rule::in(['share', 'upload'])],
            'allow_duplicate' => ['nullable', 'boolean'],
            'import_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (VideoDownload::sourceOf((string) $value) === null) {
                        $fail('Paste a YouTube or Facebook video link.');
                    }
                },
            ],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:5000'],
            // One video, or up to 10 photos (media[] from the form, or a single file).
            ...(is_array($this->file('media'))
                ? ['media' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS], 'media.*' => $this->mediaFileRules()]
                : ['media' => ['nullable', ...$this->mediaFileRules()]]),
            'music_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail) {
                    $isAudioFile = preg_match('/\.(mp3|m4a|aac|wav|ogg)$/i', (string) parse_url((string) $value, PHP_URL_PATH)) === 1;

                    if (! $isAudioFile && VideoDownload::sourceOf((string) $value) === null) {
                        $fail('Use a YouTube/Facebook link or a direct link to an MP3/M4A/WAV file.');
                    }
                },
            ],
            'thumbnail' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:20480'],
            'scheduled_at' => ['nullable', 'date'],
            'stagger_seconds' => ['required', 'integer', 'min:0', 'max:600'],

            'share_mode' => ['nullable', Rule::in([self::SHARE_MODE_SEPARATE, self::SHARE_MODE_SHARE])],
            'share_from_account_id' => ['nullable', 'required_if:share_mode,'.self::SHARE_MODE_SHARE, 'integer'],
            'share_message' => ['nullable', 'string', 'max:2000'],

            'location_id' => ['nullable', 'string', 'regex:/^\d{5,25}$/'],
            'instagram_user_tags' => ['nullable', 'string', 'max:500'],
            'instagram_collaborators' => ['nullable', 'string', 'max:200'],
            'youtube_tags' => ['nullable', 'string', 'max:500'],
            'youtube_privacy' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'youtube_format' => ['nullable', Rule::in(['shorts', 'video', 'post'])],
            'tiktok_privacy' => ['nullable', Rule::in(['public', 'private'])],
            'tiktok_allow_comments' => ['nullable', 'boolean'],
            'tiktok_allow_duet' => ['nullable', 'boolean'],
            'tiktok_allow_stitch' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accounts.required' => 'Select at least one account.',
            'media.mimetypes' => 'Upload a JPG/PNG photo or an MP4/MOV video.',
            'media.*.mimetypes' => 'Upload JPG/PNG photos or one MP4/MOV video.',
            'media.max' => 'Upload at most '.self::MAX_PHOTOS.' photos.',
            'thumbnail.mimetypes' => 'The thumbnail must be a JPG, PNG or WebP image.',
            'thumbnail.max' => 'The thumbnail must be 20 MB or smaller.',
            'share_from_account_id.required_if' => 'Choose the main Page that the other Pages will share from.',
            'location_id.regex' => 'The location must be the numeric ID of a Facebook place Page.',
        ];
    }

    /**
     * Platform specific rules that depend on which accounts were selected.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $platforms = $this->selectedAccounts()->pluck('platform')->unique()->values();
                $mediaFiles = $this->mediaFiles();
                $media = $mediaFiles[0] ?? null;

                if (count($mediaFiles) > 1 && collect($mediaFiles)->contains(fn ($file) => str_starts_with((string) $file->getMimeType(), 'video/'))) {
                    $validator->errors()->add('media', 'Upload one video, or up to '.self::MAX_PHOTOS.' photos (not both).');
                }
                $importsVideo = $this->filled('import_url');
                $isVideo = $importsVideo || ($media !== null && str_starts_with((string) $media->getMimeType(), 'video/'));

                if ($importsVideo && ($media !== null || ($this->filled('link') && $this->input('link') !== $this->input('import_url')))) {
                    $validator->errors()->add('import_url', 'Use only one: a video from a link, an uploaded photo/video, or a shared link.');
                }

                if ($media === null && ! $importsVideo && ! $this->filled('link') && trim((string) $this->input('caption')) === '') {
                    $validator->errors()->add('caption', 'Write a caption, attach a photo/video, or add a link.');
                }

                if ($this->hasFile('thumbnail') && ! $isVideo) {
                    $validator->errors()->add('thumbnail', 'A thumbnail can only be added to a video.');
                }

                if ($this->filled('link')) {
                    if ($media !== null) {
                        $validator->errors()->add('link', 'Share either a link or a photo/video, not both.');
                    }

                    if (! $importsVideo && $platforms->contains(fn (Platform $platform) => $platform !== Platform::Facebook)) {
                        $validator->errors()->add('link', 'Links can only be shared to Facebook Pages. Untick the other accounts.');
                    }
                }

                foreach ($platforms as $platform) {
                    if ($media === null && ! $importsVideo && $platform->requiresMedia()) {
                        $validator->errors()->add('media', "{$platform->label()} needs a photo or video.");
                    } elseif ($media !== null && ! $isVideo && ! $platform->acceptsPhotos()) {
                        $validator->errors()->add('media', "{$platform->label()} only accepts videos. Untick the YouTube channels or upload a video.");
                    }
                }

                if ($platforms->contains(Platform::Instagram) && ! $isVideo && collect($mediaFiles)->contains(fn ($file) => $file->getMimeType() !== 'image/jpeg')) {
                    $validator->errors()->add('media', 'Instagram only accepts JPG photos.');
                }

                if ($platforms->contains(Platform::Instagram) && ! $isVideo && collect($mediaFiles)->contains(fn ($file) => $file->getSize() > 8 * 1024 * 1024)) {
                    $validator->errors()->add('media', 'Instagram photos must be 8 MB or smaller.');
                }

                foreach ([Platform::Instagram, Platform::TikTok] as $platform) {
                    if ($platforms->contains($platform) && mb_strlen($this->captionFor($platform)) > 2200) {
                        $validator->errors()->add('caption', "The {$platform->label()} caption can be at most 2200 characters.");
                    }
                }

                if (count($this->usernames('instagram_collaborators')) > 3) {
                    $validator->errors()->add('instagram_collaborators', 'Instagram allows at most 3 collaborators.');
                }

                foreach (['instagram_user_tags', 'instagram_collaborators'] as $field) {
                    foreach ($this->usernames($field) as $username) {
                        if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $username) !== 1) {
                            $validator->errors()->add($field, "\"{$username}\" is not a valid Instagram username.");
                        }
                    }
                }

                if ($this->input('share_mode') === self::SHARE_MODE_SHARE) {
                    $mainPage = $this->selectedAccounts()->firstWhere('id', (int) $this->input('share_from_account_id'));

                    if ($mainPage === null || $mainPage->platform !== Platform::Facebook) {
                        $validator->errors()->add('share_from_account_id', 'The main Page must be one of the selected Facebook Pages.');
                    } elseif ($this->selectedAccounts()->where('platform', Platform::Facebook)->count() < 2) {
                        $validator->errors()->add('share_from_account_id', 'Select at least two Facebook Pages to use share mode.');
                    }
                }

                if (! $this->boolean('allow_duplicate') && ($duplicate = $this->recentDuplicate()) !== null) {
                    $validator->errors()->add('duplicate', "This is the same as post #{$duplicate->id}, created {$duplicate->created_at->diffForHumans()} (same text, media and accounts). "
                        .'Tick "Post it again anyway" below to post it again (choose the photo/video again).');
                }

                if ($this->filled('scheduled_at')) {
                    $scheduledAt = Carbon::parse($this->input('scheduled_at'), config('app.display_timezone'));

                    if ($scheduledAt->isPast()) {
                        $validator->errors()->add('scheduled_at', 'The schedule time must be in the future.');
                    }
                }
            },
        ];
    }

    /**
     * The per-platform settings stored on the post.
     *
     * @return array<string, mixed>
     */
    public function postOptions(): array
    {
        $captions = collect($this->input('captions', []))
            ->only(array_map(fn (Platform $platform) => $platform->value, Platform::cases()))
            ->map(fn ($caption) => trim((string) $caption))
            ->filter()
            ->all();

        return array_filter([
            'captions' => $captions,
            'link' => $this->input('link'),
            'import_url' => $this->input('import_url'),
            'music_url' => $this->input('music_url'),
            'location_id' => $this->input('location_id'),
            'share_message' => $this->usesShareMode() ? $this->input('share_message') : null,
            'instagram' => array_filter([
                'user_tags' => $this->usernames('instagram_user_tags'),
                'collaborators' => $this->usernames('instagram_collaborators'),
            ]),
            'youtube' => array_filter([
                'tags' => $this->listFrom('youtube_tags'),
                'privacy' => $this->input('youtube_privacy'),
                'format' => $this->input('youtube_format'),
            ]),
            'tiktok' => [
                'privacy' => $this->input('tiktok_privacy', 'public'),
                'allow_comments' => $this->boolean('tiktok_allow_comments'),
                'allow_duet' => $this->boolean('tiktok_allow_duet'),
                'allow_stitch' => $this->boolean('tiktok_allow_stitch'),
            ],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Identifies the content of this post, to catch the same post being
     * created twice (double click, or posting it again by mistake).
     */
    public function fingerprint(): string
    {
        $accounts = array_map('intval', (array) $this->input('accounts', []));
        sort($accounts);

        return sha1((string) json_encode([
            trim((string) $this->input('caption')),
            collect($this->input('captions', []))->map(fn ($caption) => trim((string) $caption))->filter()->sortKeys()->all(),
            trim((string) $this->input('title')),
            $this->input('link'),
            $this->input('import_url'),
            $accounts,
            array_map(fn (UploadedFile $file) => $file->getSize().':'.md5((string) file_get_contents($file->getRealPath(), length: 2 * 1024 * 1024)), $this->mediaFiles()),
        ]));
    }

    private function recentDuplicate(): ?Post
    {
        return $this->user()->posts()
            ->where('fingerprint', $this->fingerprint())
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->first();
    }

    public function usesShareMode(): bool
    {
        return $this->input('share_mode') === self::SHARE_MODE_SHARE;
    }

    private function captionFor(Platform $platform): string
    {
        $custom = trim((string) $this->input('captions.'.$platform->value));

        return $custom !== '' ? $custom : (string) $this->input('caption');
    }

    /**
     * @return list<string>
     */
    private function usernames(string $field): array
    {
        return array_map(fn (string $username) => ltrim($username, '@'), $this->listFrom($field));
    }

    /**
     * Split a comma separated field into a clean list.
     *
     * @return list<string>
     */
    private function listFrom(string $field): array
    {
        return collect(explode(',', (string) $this->input($field)))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function selectedAccounts(): Collection
    {
        return $this->selectedAccounts ??= SocialAccount::query()
            ->whereIn('id', array_filter((array) $this->input('accounts', []), 'is_numeric'))
            ->get();
    }
}
