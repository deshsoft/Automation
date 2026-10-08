<x-layout :title="'Edit post #'.$post->id">
    @php($isFailed = in_array($post->status, [\App\Enums\PostStatus::Failed, \App\Enums\PostStatus::PartiallyFailed], true))
    @php($defaultWhen = $post->status === \App\Enums\PostStatus::Scheduled ? 'schedule' : ($isFailed ? 'now' : 'keep'))
    @php($localSchedule = $post->scheduled_at?->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i'))
    @php($isPublished = $post->status === \App\Enums\PostStatus::Published)

    <form method="POST" action="{{ route('posts.update', $post) }}" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="flex flex-wrap items-center gap-3">
            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $post->status->color() }}">{{ $post->status->label() }}</span>
            <a href="{{ route('posts.show', $post) }}" class="text-sm text-slate-500 hover:text-slate-900">← Back to the post</a>
        </div>

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($post->hasPublishedTargets())
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                <strong>Already published posts are updated automatically:</strong> on <strong>Facebook</strong> the text, and on <strong>YouTube</strong> the title, description, tags and visibility.
                Instagram and TikTok do not allow editing after posting, so they keep the old text.
            </div>
        @endif

        {{-- Accounts --}}
        <section class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="mb-3 font-semibold">Accounts</h2>
            @if ($canChangeAccounts)
                @php($selected = old('accounts', $post->targets->pluck('social_account_id')->all()))
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($accounts as $account)
                        <label class="flex cursor-pointer items-center gap-3 rounded-md border border-gray-200 px-3 py-2 hover:bg-gray-50 has-checked:border-indigo-400 has-checked:bg-indigo-50">
                            <input type="checkbox" name="accounts[]" value="{{ $account->id }}" class="rounded" @checked(in_array($account->id, $selected))>
                            <x-platform-badge :platform="$account->platform" />
                            <span class="truncate text-sm">{{ $account->name }}</span>
                        </label>
                    @endforeach
                </div>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($post->targets as $target)
                        <li class="flex items-center gap-2">
                            <x-platform-badge :platform="$target->socialAccount->platform" />
                            <span class="flex-1 truncate">{{ $target->socialAccount->name }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $target->status->color() }}">{{ $target->status->label() }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-500">Some accounts already have this post, so the list cannot change. Saving retries only the failed ones.</p>
            @endif
        </section>

        {{-- Media (cannot change once the post is published everywhere) --}}
        <section @class(['rounded-lg border border-gray-200 bg-white p-5', 'hidden' => $isPublished])>
            <h2 class="mb-3 font-semibold">Photo or video</h2>
            @if ($post->hasMedia())
                <div class="mb-3 flex flex-wrap gap-2">
                    @if ($post->isVideo())
                        <video src="{{ $post->mediaUrl() }}" controls class="h-40 rounded-md"></video>
                    @else
                        @foreach ($post->photoUrls() as $photoUrl)
                            <img src="{{ $photoUrl }}" alt="" class="size-24 rounded-md object-cover">
                        @endforeach
                    @endif
                </div>
            @elseif ($post->option('import_url'))
                <p class="mb-3 text-sm text-gray-600">Taken from a link: <span class="break-all text-indigo-600">{{ $post->option('import_url') }}</span></p>
            @endif
            <label for="media" class="mb-1 block text-sm font-medium">Replace with new files <span class="font-normal text-gray-500">(optional)</span></label>
            <input id="media" name="media[]" type="file" multiple accept="image/jpeg,image/png,image/heic,image/heif,.heic,.heif,video/mp4,video/quicktime" data-heic-convert
                   class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-indigo-700">
            <p class="mt-1 text-xs text-gray-500">One video, or up to 10 photos. Leave empty to keep the current ones.</p>
        </section>

        {{-- Text --}}
        <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
            <div>
                <label for="caption" class="mb-1 block font-semibold">Caption</label>
                <textarea id="caption" name="caption" rows="6" maxlength="5000"
                          class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">{{ old('caption', $post->caption) }}</textarea>
            </div>
            <details class="rounded-md border border-gray-200" @if (array_filter(old('captions', $post->option('captions', [])))) open @endif>
                <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700">Customize caption for each platform</summary>
                <div class="space-y-3 border-t border-gray-200 p-3">
                    @foreach (\App\Enums\Platform::cases() as $platform)
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ $platform === \App\Enums\Platform::YouTube ? 'YouTube description' : $platform->label() }}</label>
                            <textarea name="captions[{{ $platform->value }}]" rows="3" maxlength="5000"
                                      class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">{{ old('captions.'.$platform->value, $post->option('captions.'.$platform->value)) }}</textarea>
                        </div>
                    @endforeach
                </div>
            </details>
            <div>
                <label for="location_id" class="mb-1 block text-sm font-medium">Location ID <span class="font-normal text-gray-500">(Facebook &amp; Instagram, optional)</span></label>
                <input id="location_id" name="location_id" type="text" inputmode="numeric" value="{{ old('location_id', $post->option('location_id')) }}"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
            </div>
        </section>

        {{-- YouTube --}}
        <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="font-semibold">YouTube</h2>
            <div>
                <label for="title" class="mb-1 block text-sm font-medium">Video title</label>
                <input id="title" name="title" type="text" maxlength="100" value="{{ old('title', $post->title) }}" placeholder="Uses the first line of the caption when empty"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
            </div>
            <div>
                <div class="mb-1 text-sm font-medium">When the post has photos, make a…</div>
                <div class="flex flex-wrap gap-4 text-sm">
                    @foreach (['shorts' => '📱 Shorts', 'video' => '🖥️ Regular video', 'post' => '🖼️ Photo post'] as $format => $label)
                        <label class="flex items-center gap-2">
                            <input type="radio" name="youtube_format" value="{{ $format }}" @checked(old('youtube_format', $post->option('youtube.format', 'shorts')) === $format)> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="youtube_tags" class="mb-1 block text-sm font-medium">Tags</label>
                    <input id="youtube_tags" name="youtube_tags" type="text" value="{{ old('youtube_tags', implode(', ', $post->option('youtube.tags', []))) }}"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                </div>
                <div>
                    <label for="youtube_privacy" class="mb-1 block text-sm font-medium">Visibility</label>
                    <select id="youtube_privacy" name="youtube_privacy" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        @foreach (['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('youtube_privacy', $post->option('youtube.privacy', config('services.youtube.privacy'))) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="music_url" class="mb-1 block text-sm font-medium">Background music <span class="font-normal text-gray-500">(for videos made from photos)</span></label>
                <input id="music_url" name="music_url" type="text" inputmode="url" value="{{ old('music_url', $post->option('music_url')) }}" placeholder="YouTube link or a direct .mp3 link"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
            </div>
        </section>

        {{-- When --}}
        @if ($isPublished)
            <input type="hidden" name="when" value="keep">
        @endif
        <section @class(['space-y-3 rounded-lg border border-gray-200 bg-white p-5', 'hidden' => $isPublished])>
            <h2 class="font-semibold">After saving</h2>
            @foreach ([
                'now' => $isFailed ? '🔁 Retry the failed accounts now' : '🚀 Publish now',
                'schedule' => '🕒 Schedule',
                'keep' => '💾 Only save (do not publish)',
            ] as $value => $label)
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="when" value="{{ $value }}" data-edit-when @checked(old('when', $defaultWhen) === $value) @disabled($isPublished)> {{ $label }}
                </label>
            @endforeach
            <div data-edit-schedule @class(['hidden' => old('when', $defaultWhen) !== 'schedule'])>
                <label for="scheduled_at" class="mb-1 block text-sm font-medium">Date and time ({{ config('app.display_timezone') }})</label>
                <input id="scheduled_at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at', $localSchedule) }}"
                       class="rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
            </div>
        </section>

        <div class="flex items-center gap-3">
            <button class="rounded-md bg-indigo-600 px-6 py-2.5 font-medium text-white hover:bg-indigo-700">Save changes</button>
            <a href="{{ route('posts.show', $post) }}" class="text-sm text-gray-500 hover:text-gray-900">Cancel</a>
        </div>
    </form>
</x-layout>
