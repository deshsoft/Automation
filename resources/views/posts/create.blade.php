<x-layout title="Create post" wide>

    @if ($accounts->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
            Connect an account first on the <a href="{{ route('accounts.index') }}" class="text-indigo-600 underline">Accounts</a> page.
        </div>
    @else
        <form method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data" data-composer
              class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            @csrf

            <div class="min-w-0 space-y-5">
                @if ($errors->any())
                    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Accounts --}}
                <section class="rounded-lg border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-semibold">Post to</h2>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" data-select-all class="rounded"> Select all
                        </label>
                    </div>

                    @foreach ($accounts->groupBy(fn ($account) => $account->platform->value) as $platformValue => $platformAccounts)
                        <div class="mb-4 last:mb-0">
                            <div class="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">{{ $platformAccounts->first()->platform->label() }}</div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($platformAccounts as $account)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-md border border-gray-200 px-3 py-2 hover:bg-gray-50 has-checked:border-indigo-400 has-checked:bg-indigo-50">
                                        <input type="checkbox" name="accounts[]" value="{{ $account->id }}" class="rounded"
                                               data-account data-platform="{{ $platformValue }}" data-name="{{ $account->name }}" data-avatar="{{ $account->avatar_url }}"
                                               @checked(in_array($account->id, old('accounts', [])))>
                                        @if ($account->avatar_url)
                                            <img src="{{ $account->avatar_url }}" alt="" class="size-6 rounded-full">
                                        @endif
                                        <span class="truncate text-sm">{{ $account->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>

                {{-- Video from a link --}}
                <section class="rounded-lg border border-purple-200 bg-purple-50/40 p-5">
                    <label for="import_url" class="mb-1 block font-semibold">🎬 Video from a link <span class="font-normal text-gray-500">(YouTube or Facebook, optional)</span></label>
                    <input id="import_url" name="import_url" type="text" inputmode="url" value="{{ old('import_url') }}" data-import-input
                           placeholder="https://www.youtube.com/watch?v=…  or  https://www.facebook.com/…/videos/…"
                           class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 focus:border-indigo-500 focus:outline-none">
                    <p class="mt-1 text-xs text-gray-600">
                        The server downloads the video, then uploads it to every account you selected (YouTube, TikTok, Facebook, Instagram).
                        Use this instead of uploading a file. YouTube and TikTok have no "share" option, so this is the way to repost there.
                    </p>
                    <p class="mt-1 text-xs text-amber-700">Only repost videos you own or have permission to use: re-uploading other channels' videos can cause copyright strikes.</p>
                </section>

                {{-- Link --}}
                <section class="rounded-lg border border-gray-200 bg-white p-5">
                    <label for="link" class="mb-1 block font-semibold">🔗 Share a link <span class="font-normal text-gray-500">(optional, Facebook Pages only)</span></label>
                    <input id="link" name="link" type="text" inputmode="url" value="{{ old('link') }}" data-link-input
                           placeholder="Paste a Facebook post, news or YouTube link"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
                    <p data-link-warning class="mt-1 hidden text-sm font-medium text-red-600">
                        This is not a link. Paste the post's address (it starts with https://), not its text.
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        To share someone else's post: open it on Facebook, click <strong>⋯ → Copy link</strong> (or <strong>Share → Copy link</strong>), and paste it here.
                        The post must be <strong>public</strong>. Write your own text in the <strong>Caption</strong> box below; it appears above the shared post.
                        Do not add a photo/video with a link.
                    </p>
                </section>

                {{-- Media --}}
                <section class="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 font-semibold">Photo or video</h2>
                    <label data-dropzone class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-8 text-center hover:border-indigo-400 hover:bg-indigo-50/40">
                        <svg class="size-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/></svg>
                        <span class="text-sm font-medium text-indigo-600">Click to choose, or drag a file here</span>
                        <span class="text-xs text-gray-500">JPG/PNG photo or MP4/MOV video · max {{ config('filesystems.max_upload_mb') }} MB</span>
                        <input id="media" name="media" type="file" accept="image/jpeg,image/png,video/mp4,video/quicktime" class="sr-only" data-media-input>
                    </label>

                    <div data-media-selected class="mt-3 hidden items-center gap-3 rounded-md bg-gray-50 p-2">
                        <div data-media-thumb class="size-16 shrink-0 overflow-hidden rounded bg-gray-200"></div>
                        <div class="min-w-0 flex-1 text-sm">
                            <div data-media-name class="truncate font-medium"></div>
                            <div data-media-info class="text-xs text-gray-500"></div>
                        </div>
                        <button type="button" data-media-remove class="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50">Remove</button>
                    </div>
                    <p data-media-warning class="mt-2 hidden text-xs text-amber-700"></p>

                    <div data-thumbnail-field class="mt-4 hidden border-t border-gray-100 pt-4">
                        <label for="thumbnail" class="mb-1 block text-sm font-medium">Thumbnail <span class="font-normal text-gray-500">(optional cover image for the video)</span></label>
                        <div class="flex items-center gap-3">
                            <img data-thumbnail-preview alt="" class="hidden h-16 w-28 shrink-0 rounded object-cover">
                            <input id="thumbnail" name="thumbnail" type="file" accept="image/jpeg,image/png,image/webp" data-thumbnail-input
                                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            JPG, PNG or WebP, up to 20 MB (big images are shrunk automatically). 1280×720 recommended. Used by Facebook, YouTube and Instagram.
                            YouTube needs a phone-verified channel for custom thumbnails. TikTok does not accept one.
                        </p>
                    </div>
                </section>

                {{-- Caption --}}
                <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                    <div>
                        <label for="caption" class="mb-1 block font-semibold">Caption</label>
                        <textarea id="caption" name="caption" rows="6" maxlength="5000" placeholder="What do you want to say?"
                                  class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">{{ old('caption') }}</textarea>
                        <p class="mt-1 text-xs text-gray-500"><span data-caption-count>0</span> characters · use #hashtags in the text · Instagram and TikTok allow up to 2200</p>
                    </div>

                    <details class="group rounded-md border border-gray-200" @if (array_filter(old('captions', []))) open @endif>
                        <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700">Customize caption for each platform</summary>
                        <div class="space-y-3 border-t border-gray-200 p-3">
                            <p class="text-xs text-gray-500">Leave empty to use the main caption. Different text per platform also looks less like spam.</p>
                            @foreach (\App\Enums\Platform::cases() as $platform)
                                <div data-platform-section="{{ $platform->value }}">
                                    <label class="mb-1 block text-sm font-medium">{{ $platform === \App\Enums\Platform::YouTube ? 'YouTube description' : $platform->label() }}</label>
                                    <textarea name="captions[{{ $platform->value }}]" rows="3" maxlength="5000" data-platform-caption="{{ $platform->value }}"
                                              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">{{ old('captions.'.$platform->value) }}</textarea>
                                </div>
                            @endforeach
                        </div>
                    </details>
                </section>

                {{-- Facebook options --}}
                <section data-platform-section="facebook" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="font-semibold">Facebook options</h2>

                    <div class="space-y-2">
                        <label class="flex items-start gap-2 text-sm">
                            <input type="radio" name="share_mode" value="separate" data-share-mode class="mt-0.5" @checked(old('share_mode', 'separate') === 'separate')>
                            <span><span class="font-medium">Separate post on each Page</span><br><span class="text-gray-500">Each Page gets its own post (usually reaches more people).</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm">
                            <input type="radio" name="share_mode" value="share" data-share-mode class="mt-0.5" @checked(old('share_mode') === 'share')>
                            <span><span class="font-medium">Post on a main Page, other Pages share it</span><br><span class="text-gray-500">All likes and comments gather on one main post.</span></span>
                        </label>
                    </div>

                    <div data-share-fields @class(['space-y-3 rounded-md bg-gray-50 p-3', 'hidden' => old('share_mode') !== 'share'])>
                        <div>
                            <label for="share_from_account_id" class="mb-1 block text-sm font-medium">Main Page</label>
                            <select id="share_from_account_id" name="share_from_account_id" data-share-from data-old="{{ old('share_from_account_id') }}"
                                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></select>
                            <p class="mt-1 text-xs text-gray-500">Select at least two Facebook Pages above.</p>
                        </div>
                        <div>
                            <label for="share_message" class="mb-1 block text-sm font-medium">Text the other Pages add when sharing <span class="font-normal text-gray-500">(optional)</span></label>
                            <textarea id="share_message" name="share_message" rows="2" maxlength="2000"
                                      class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">{{ old('share_message') }}</textarea>
                        </div>
                    </div>
                </section>

                {{-- Location (Facebook + Instagram) --}}
                <section data-platform-section="facebook instagram" class="rounded-lg border border-gray-200 bg-white p-5">
                    <label for="location_id" class="mb-1 block font-semibold">Location <span class="font-normal text-gray-500">(Facebook &amp; Instagram, optional)</span></label>
                    <input id="location_id" name="location_id" type="text" inputmode="numeric" value="{{ old('location_id') }}" placeholder="e.g. 108141032537433"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
                    <p class="mt-1 text-xs text-gray-500">
                        The ID of the place's Facebook Page (a city, venue or area with an address). Open the place's Page →
                        <em>About → Page transparency</em> to find its ID. Facebook does not let apps search places without special approval.
                    </p>
                </section>

                {{-- Instagram options --}}
                <section data-platform-section="instagram" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="font-semibold">Instagram options</h2>
                    <div>
                        <label for="instagram_user_tags" class="mb-1 block text-sm font-medium">Tag people</label>
                        <input id="instagram_user_tags" name="instagram_user_tags" type="text" value="{{ old('instagram_user_tags') }}" placeholder="username1, username2"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                        <p class="mt-1 text-xs text-gray-500">Instagram usernames, separated by commas. The accounts must be public.</p>
                    </div>
                    <div>
                        <label for="instagram_collaborators" class="mb-1 block text-sm font-medium">Collaborators <span class="font-normal text-gray-500">(up to 3)</span></label>
                        <input id="instagram_collaborators" name="instagram_collaborators" type="text" value="{{ old('instagram_collaborators') }}" placeholder="username1, username2"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                        <p class="mt-1 text-xs text-gray-500">They get an invite. When accepted, the post also shows on their profile.</p>
                    </div>
                </section>

                {{-- YouTube options --}}
                <section data-platform-section="youtube" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="font-semibold">YouTube options</h2>
                    <div>
                        <label for="title" class="mb-1 block text-sm font-medium">Video title</label>
                        <input id="title" name="title" type="text" maxlength="100" value="{{ old('title') }}"
                               placeholder="Uses the first line of the caption when empty"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label for="youtube_tags" class="mb-1 block text-sm font-medium">Tags</label>
                        <input id="youtube_tags" name="youtube_tags" type="text" value="{{ old('youtube_tags') }}" placeholder="dhaka, election, speech"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label for="youtube_privacy" class="mb-1 block text-sm font-medium">Visibility</label>
                        <select id="youtube_privacy" name="youtube_privacy" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                            @foreach (['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('youtube_privacy', config('services.youtube.privacy')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </section>

                {{-- TikTok options --}}
                <section data-platform-section="tiktok" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="font-semibold">TikTok options</h2>
                    <div>
                        <label for="tiktok_privacy" class="mb-1 block text-sm font-medium">Who can watch</label>
                        <select id="tiktok_privacy" name="tiktok_privacy" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option value="public" @selected(old('tiktok_privacy', 'public') === 'public')>Everyone (when TikTok allows it)</option>
                            <option value="private" @selected(old('tiktok_privacy') === 'private')>Only me</option>
                        </select>
                    </div>
                    <div class="flex flex-wrap gap-5 text-sm">
                        @foreach (['tiktok_allow_comments' => 'Allow comments', 'tiktok_allow_duet' => 'Allow Duet', 'tiktok_allow_stitch' => 'Allow Stitch'] as $field => $label)
                            <label class="flex items-center gap-2">
                                <input type="hidden" name="{{ $field }}" value="0">
                                <input type="checkbox" name="{{ $field }}" value="1" class="rounded" @checked(old($field, '1') === '1')> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- When --}}
                <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 class="font-semibold">When to publish</h2>
                    <div class="flex flex-wrap gap-6">
                        <label class="flex items-center gap-2">
                            <input type="radio" name="when" value="now" data-when @checked(! old('scheduled_at'))> Post now
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="when" value="later" data-when @checked(old('scheduled_at'))> Schedule
                        </label>
                    </div>

                    <div data-schedule-fields @class(['hidden' => ! old('scheduled_at')])>
                        <label for="scheduled_at" class="mb-1 block text-sm font-medium">Date and time ({{ config('app.display_timezone') }})</label>
                        <input id="scheduled_at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}"
                               class="rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label for="stagger_seconds" class="mb-1 block text-sm font-medium">Gap between accounts</label>
                        <select id="stagger_seconds" name="stagger_seconds" class="rounded-md border border-gray-300 px-3 py-2">
                            @foreach ([0 => 'No gap (all at once)', 30 => '30 seconds', 60 => '1 minute', 120 => '2 minutes', 300 => '5 minutes'] as $seconds => $label)
                                <option value="{{ $seconds }}" @selected((int) old('stagger_seconds', 60) === $seconds)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Posting to many Pages at the exact same moment can look like spam to Facebook. A small gap is safer.</p>
                    </div>
                </section>

                <div class="flex items-center gap-4">
                    <button data-submit class="rounded-md bg-indigo-600 px-6 py-2.5 font-medium text-white hover:bg-indigo-700 disabled:opacity-60">Publish</button>
                    <span data-upload-status class="hidden text-sm text-gray-500">Uploading… please keep this page open.</span>
                </div>
            </div>

            {{-- Live preview --}}
            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Preview</h2>
                    <div data-preview-tabs class="flex gap-1 text-xs"></div>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 px-4 pt-4 pb-2">
                        <div data-preview-avatar class="size-9 shrink-0 overflow-hidden rounded-full bg-gray-200"></div>
                        <div class="min-w-0">
                            <div data-preview-name class="truncate text-sm font-semibold">Your Page</div>
                            <div data-preview-meta class="text-xs text-gray-500">Just now · 🌐</div>
                        </div>
                    </div>
                    <p data-preview-caption class="px-4 pb-3 text-sm break-words whitespace-pre-line text-gray-800"></p>
                    <div data-preview-link class="mx-4 mb-4 hidden overflow-hidden rounded-lg border border-gray-300" data-preview-url="{{ route('link-preview') }}">
                        <img data-preview-link-image alt="" class="hidden max-h-72 w-full bg-gray-100 object-cover">
                        <div class="bg-gray-50 px-3 py-2">
                            <div data-preview-link-site class="truncate text-xs text-gray-500 uppercase"></div>
                            <div data-preview-link-title class="line-clamp-2 text-sm font-semibold text-gray-900"></div>
                            <div data-preview-link-description class="mt-0.5 line-clamp-3 text-xs whitespace-pre-line text-gray-600"></div>
                            <div data-preview-link-status class="text-xs text-gray-400"></div>
                        </div>
                    </div>
                    <div data-preview-media class="hidden bg-black"></div>
                    <div data-preview-placeholder class="mx-4 mb-4 flex h-40 items-center justify-center rounded-md bg-gray-100 px-4 text-center text-sm text-gray-400" data-default-text="Photo or video preview">Photo or video preview</div>
                    <div class="flex justify-around border-t border-gray-100 py-2 text-xs text-gray-500">
                        <span>👍 Like</span><span>💬 Comment</span><span>↗ Share</span>
                    </div>
                </div>
                <p data-preview-note class="mt-2 text-xs text-gray-500">Select accounts to see how the post will look.</p>
            </aside>
        </form>
    @endif
</x-layout>
