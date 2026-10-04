<x-layout title="Post" :refresh="$post->isBusy()">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold">Post #{{ $post->id }}</h1>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $post->status->color() }}">{{ $post->status->label() }}</span>
        </div>
        <div class="flex gap-2">
            @php($postLinks = $post->targets->pluck('permalink')->filter()->values())
            @if ($postLinks->isNotEmpty())
                <button type="button" data-open-all='@json($postLinks)'
                        class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Open all posts ({{ $postLinks->count() }})
                </button>
            @endif
            @if ($post->targets->contains(fn ($target) => $target->status === \App\Enums\TargetStatus::Failed) && ! $post->isBusy())
                <form method="POST" action="{{ route('posts.retry', $post) }}">
                    @csrf
                    <button class="rounded-md bg-amber-500 px-3 py-2 text-sm font-medium text-white hover:bg-amber-600">Retry failed</button>
                </form>
            @endif
            @if (! $post->isBusy())
                <form method="POST" action="{{ route('posts.destroy', $post) }}" data-confirm="{{ $post->status === \App\Enums\PostStatus::Scheduled ? 'Cancel this scheduled post?' : 'Delete this post from the dashboard? (It stays on the social networks.)' }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf @method('DELETE')
                    <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ $post->status === \App\Enums\PostStatus::Scheduled ? 'Cancel' : 'Delete' }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if ($post->option('import_url'))
        <div @class([
            'mb-6 rounded-xl border px-5 py-4 text-sm',
            'border-purple-200 bg-purple-50 text-purple-900' => $post->status === \App\Enums\PostStatus::Preparing,
            'border-slate-200 bg-white text-slate-700' => $post->status !== \App\Enums\PostStatus::Preparing,
        ])>
            @if ($post->status === \App\Enums\PostStatus::Preparing)
                <div class="font-semibold">⏳ Downloading the video… this page refreshes by itself.</div>
                <div class="mt-1">When the download finishes, the video is published to the selected accounts automatically.</div>
            @else
                <div class="font-semibold">🎬 Video taken from a link</div>
            @endif
            <a href="{{ $post->option('import_url') }}" target="_blank" rel="noopener" class="mt-1 block truncate text-indigo-600 hover:underline">{{ $post->option('import_url') }}</a>
        </div>
    @endif

    @php($facebookLinks = $post->targets->filter(fn ($target) => $target->permalink && $target->socialAccount->platform === \App\Enums\Platform::Facebook)->pluck('permalink')->values())
    @if ($facebookLinks->isNotEmpty())
        <div class="mb-6 flex flex-col gap-4 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 sm:flex-row sm:items-center">
            <div class="min-w-0 flex-1 text-sm text-blue-900">
                <div class="font-semibold">🏷️ Tag yourself on these posts</div>
                <div class="mt-1 text-blue-800">
                    Facebook does not let apps tag people, so it takes a few seconds per post:
                    click <strong>Tag me</strong> → on Facebook click <strong>⋯</strong> → <strong>Edit post</strong> →
                    <strong>Tag people</strong> 🏷️ → choose your name → <strong>Save</strong>.
                </div>
            </div>
            <button type="button" data-open-all='@json($facebookLinks)'
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                🏷️ Tag me on all ({{ $facebookLinks->count() }})
            </button>
        </div>
    @endif

    <div data-popup-warning class="mb-6 hidden rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Your browser blocked some tabs. Click the pop-up icon at the right end of the address bar, choose
        <strong>Always allow pop-ups from this site</strong>, then click the button again. You can also use the <strong>View post</strong> links below.
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-6 md:col-span-1">
            @if ($post->hasMedia())
                @if ($post->isVideo())
                    <video src="{{ $post->mediaUrl() }}" @if ($post->hasThumbnail()) poster="{{ $post->thumbnailUrl() }}" @endif controls class="w-full rounded-md"></video>
                @else
                    <img src="{{ $post->mediaUrl() }}" alt="" class="w-full rounded-md">
                @endif
            @endif
            @if ($post->title)
                <div class="font-medium">{{ $post->title }}</div>
            @endif
            <p class="text-sm whitespace-pre-line text-gray-700">{{ $post->caption }}</p>
            <dl class="space-y-1 border-t border-gray-100 pt-3 text-xs text-gray-500">
                <div>Created: <x-local-time :time="$post->created_at" /></div>
                @if ($post->scheduled_at)
                    <div>Scheduled for: <x-local-time :time="$post->scheduled_at" /></div>
                @endif
                <div>Gap between accounts: {{ $post->stagger_seconds }} seconds</div>
                @if ($post->usesShareMode())
                    <div>Share mode: other Pages share the post of <strong>{{ $post->shareFromAccount?->name ?? 'the main Page' }}</strong></div>
                @endif
                @if ($post->option('location_id'))
                    <div>Location ID: {{ $post->option('location_id') }}</div>
                @endif
            </dl>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white md:col-span-2">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($post->targets as $target)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $target->socialAccount->name }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ $target->socialAccount->platform->label() }}
                                    @if ($post->usesShareMode() && $target->social_account_id === $post->share_from_account_id)
                                        · <span class="font-medium text-indigo-600">Main Page</span>
                                    @elseif ($target->isShare())
                                        · Shares the main post
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $target->status->color() }}">{{ ucfirst($target->status->value) }}</span>
                                @if ($target->published_at)
                                    <div class="mt-1 text-xs text-gray-500">
                                        <x-local-time :time="$target->published_at" />
                                        @if ($target->permalink)
                                            · <a href="{{ $target->permalink }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:underline">View post ↗</a>
                                            @if ($target->socialAccount->platform === \App\Enums\Platform::Facebook)
                                                · <a href="{{ $target->permalink }}" target="_blank" rel="noopener" class="font-medium text-blue-700 hover:underline">🏷️ Tag me</a>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                                @if ($target->error)
                                    <div class="mt-1 text-xs text-red-600">{{ $target->error }}</div>
                                @endif
                                @if ($target->state['thumbnail_error'] ?? null)
                                    <div class="mt-1 text-xs text-amber-700">{{ $target->state['thumbnail_error'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>
</x-layout>
