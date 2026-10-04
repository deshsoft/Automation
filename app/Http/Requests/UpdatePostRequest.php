<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Models\Post;
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

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('post')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mediaRules = ['file', 'mimetypes:image/jpeg,image/png,video/mp4,video/quicktime', 'max:'.((int) config('filesystems.max_upload_mb', 1024) * 1024)];

        return [
            'title' => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:5000'],
            ...(is_array($this->file('media'))
                ? ['media' => ['nullable', 'array', 'max:'.StorePostRequest::MAX_PHOTOS], 'media.*' => $mediaRules]
                : ['media' => ['nullable', ...$mediaRules]]),
            'accounts' => ['sometimes', 'array', 'min:1'],
            'accounts.*' => [
                'integer',
                'distinct',
                Rule::exists('social_accounts', 'id')->where('user_id', $this->user()->id)->where('is_active', true),
            ],
            'when' => ['required', Rule::in(['schedule', 'now', 'keep'])],
            'scheduled_at' => ['nullable', 'required_if:when,schedule', 'date'],
            'youtube_tags' => ['nullable', 'string', 'max:500'],
            'youtube_privacy' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'youtube_format' => ['nullable', Rule::in(['shorts', 'video', 'post'])],
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
            'location_id' => ['nullable', 'string', 'regex:/^\d{5,25}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.required_if' => 'Choose the new date and time.',
            'location_id.regex' => 'The location must be the numeric ID of a Facebook place Page.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Post $post */
                $post = $this->route('post');
                $files = $this->mediaFiles();
                $newVideo = collect($files)->contains(fn (UploadedFile $file) => str_starts_with((string) $file->getMimeType(), 'video/'));

                if (count($files) > 1 && $newVideo) {
                    $validator->errors()->add('media', 'Upload one video, or up to '.StorePostRequest::MAX_PHOTOS.' photos (not both).');
                }

                $hasMedia = $files !== [] || $post->hasMedia();

                if (! $hasMedia && blank($post->option('link')) && trim((string) $this->input('caption')) === '') {
                    $validator->errors()->add('caption', 'Write a caption, or attach a photo/video.');
                }

                foreach ($this->platforms($post) as $platform) {
                    if (! $hasMedia && $platform->requiresMedia() && blank($post->option('import_url'))) {
                        $validator->errors()->add('media', "{$platform->label()} needs a photo or video.");
                    }

                    $caption = trim((string) $this->input('captions.'.$platform->value)) ?: (string) $this->input('caption');

                    if (in_array($platform, [Platform::Instagram, Platform::TikTok], true) && mb_strlen($caption) > 2200) {
                        $validator->errors()->add('caption', "The {$platform->label()} caption can be at most 2200 characters.");
                    }
                }

                if ($this->input('when') === 'schedule' && Carbon::parse($this->input('scheduled_at'), config('app.display_timezone'))->isPast()) {
                    $validator->errors()->add('scheduled_at', 'The schedule time must be in the future.');
                }

                if ($this->has('accounts') && ! $post->canChangeAccounts()) {
                    $validator->errors()->add('accounts', 'Accounts can only be changed before anything was published.');
                }
            },
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function mediaFiles(): array
    {
        return array_values(array_filter(Arr::wrap($this->file('media'))));
    }

    /**
     * @return Collection<int, Platform>
     */
    private function platforms(Post $post): Collection
    {
        $accountIds = $this->has('accounts')
            ? array_filter((array) $this->input('accounts'), 'is_numeric')
            : $post->targets()->pluck('social_account_id')->all();

        return $this->user()->socialAccounts()->whereIn('id', $accountIds)->pluck('platform')->unique()->values();
    }
}
