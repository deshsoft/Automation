<x-layout title="Posts" wide>
    @php($tabs = ['' => 'All', 'scheduled' => 'Scheduled', 'publishing' => 'Publishing', 'published' => 'Published', 'failed' => 'Failed'])

    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <nav class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('posts.index', array_filter(['status' => $key, 'search' => $search])) }}" @class([
                    'rounded-md px-3 py-1.5 font-medium',
                    'bg-white text-slate-900 shadow-sm' => ($filter ?? '') === $key,
                    'text-slate-600 hover:text-slate-900' => ($filter ?? '') !== $key,
                ])>
                    {{ $label }}
                    <span class="ml-1 text-xs text-slate-400 tabular-nums">{{ $tabCounts[$key === '' ? null : $key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('posts.index') }}" class="relative w-full sm:w-72">
            @if ($filter)
                <input type="hidden" name="status" value="{{ $filter }}">
            @endif
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <input type="search" name="search" value="{{ $search }}" placeholder="Search captions…"
                   class="w-full rounded-lg border border-slate-200 bg-white py-2 pr-3 pl-9 text-sm focus:border-indigo-500 focus:outline-none">
        </form>
    </div>

    @if ($posts->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <x-icon name="posts" class="mx-auto size-10 text-slate-300" />
            <p class="mt-3 text-sm text-slate-500">{{ $search !== '' || $filter ? 'No posts match this filter.' : 'No posts yet.' }}</p>
            <a href="{{ route('posts.create') }}" class="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Create post</a>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-medium tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-3">Post</th>
                            <th class="px-5 py-3">Platforms</th>
                            <th class="px-5 py-3">Delivery</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($posts as $post)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('posts.show', $post) }}" class="flex max-w-md items-center gap-3">
                                        <x-post-thumbnail :post="$post" size="size-11" />
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-slate-900 hover:text-indigo-600">
                                                {{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 70) ?: ($post->option('link') ?: 'Untitled') }}
                                            </span>
                                            <span class="text-xs text-slate-500">
                                                {{ $post->option('link') ? 'Shared link' : ($post->media_type ? ucfirst($post->media_type) : 'Text') }}
                                                @if ($post->usesShareMode()) · Share mode @endif
                                            </span>
                                        </span>
                                    </a>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex -space-x-1">
                                        @foreach ($post->targets->pluck('socialAccount.platform')->filter()->unique() as $platform)
                                            <x-platform-badge :platform="$platform" class="ring-2 ring-white" />
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    @php($percent = $post->targets_count > 0 ? $post->published_targets_count / $post->targets_count * 100 : 0)
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full" style="width: {{ $percent }}%; background:#0ca30c"></div>
                                        </div>
                                        <span class="text-xs text-slate-600 tabular-nums">{{ $post->published_targets_count }}/{{ $post->targets_count }}</span>
                                    </div>
                                    @if ($post->failed_targets_count > 0)
                                        <div class="mt-0.5 text-xs text-red-600">✕ {{ $post->failed_targets_count }} failed</div>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap {{ $post->status->color() }}">{{ $post->status->label() }}</span>
                                </td>
                                <td class="px-5 py-3 whitespace-nowrap text-slate-600">
                                    <x-local-time :time="$post->scheduled_at ?? $post->created_at" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $posts->links() }}</div>
    @endif
</x-layout>
