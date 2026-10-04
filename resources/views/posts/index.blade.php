<x-layout title="Posts" wide>
    @php($tabs = ['' => 'All', 'scheduled' => 'Scheduled', 'publishing' => 'Publishing', 'published' => 'Published', 'failed' => 'Failed'])

    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <nav class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('posts.index', array_filter(['status' => $key, 'search' => $search, 'platform' => $platform?->value, 'per_page' => $perPage === 20 ? null : $perPage])) }}" @class([
                    'rounded-md px-3 py-1.5 font-medium',
                    'bg-white text-slate-900 shadow-sm' => ($filter ?? '') === $key,
                    'text-slate-600 hover:text-slate-900' => ($filter ?? '') !== $key,
                ])>
                    {{ $label }}
                    <span class="ml-1 text-xs text-slate-400 tabular-nums">{{ $tabCounts[$key === '' ? null : $key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('posts.index') }}" data-filter-form class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            @if ($filter)
                <input type="hidden" name="status" value="{{ $filter }}">
            @endif
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <select name="platform" data-auto-submit aria-label="Platform"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                <option value="">All platforms</option>
                @foreach (\App\Enums\Platform::cases() as $option)
                    <option value="{{ $option->value }}" @selected($platform === $option)>{{ $option->label() }}</option>
                @endforeach
            </select>
            <div class="relative min-w-0 flex-1 sm:w-64 sm:flex-none">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ $search }}" placeholder="Search text or links…"
                       class="w-full rounded-lg border border-slate-200 bg-white py-2 pr-3 pl-9 text-sm focus:border-indigo-500 focus:outline-none">
            </div>
            <button class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">Search</button>
            @if ($search !== '' || $platform)
                <a href="{{ route('posts.index', array_filter(['status' => $filter])) }}" class="text-sm text-slate-500 hover:text-slate-900">Clear</a>
            @endif
        </form>
    </div>

    @if ($posts->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <x-icon name="posts" class="mx-auto size-10 text-slate-300" />
            <p class="mt-3 text-sm text-slate-500">{{ $search !== '' || $filter || $platform ? 'No posts match this filter.' : 'No posts yet.' }}</p>
            <a href="{{ route('posts.create') }}" class="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Create post</a>
        </div>
    @else
        <form method="POST" action="{{ route('posts.bulk-destroy') }}" id="bulk-delete" data-bulk-form
              data-confirm="Delete the selected posts from this app? They stay on Facebook, YouTube and TikTok." onsubmit="return confirm(this.dataset.confirm)">
            @csrf
        </form>

        <div data-bulk-bar class="mb-3 hidden items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm">
            <span class="text-red-800"><strong data-bulk-count>0</strong> selected</span>
            <button form="bulk-delete" class="rounded-lg bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-700">🗑️ Delete selected</button>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="relative overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-medium tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="w-10 py-3 pl-4 sm:pl-5"><input type="checkbox" data-bulk-all class="rounded" aria-label="Select all posts on this page"></th>
                            <th class="px-3 py-3 sm:px-5">Post</th>
                            <th class="hidden px-5 py-3 md:table-cell">Platforms</th>
                            <th class="hidden px-5 py-3 lg:table-cell">Delivery</th>
                            <th class="hidden px-5 py-3 sm:table-cell">Status</th>
                            <th class="hidden px-5 py-3 md:table-cell">When</th>
                            <th class="px-3 py-3 sm:px-5"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($posts as $post)
                            <tr class="hover:bg-slate-50">
                                <td class="py-3 pl-4 sm:pl-5">
                                    <input type="checkbox" name="posts[]" value="{{ $post->id }}" form="bulk-delete" data-bulk-item class="rounded"
                                           aria-label="Select post {{ $post->id }}" @disabled($post->isBusy())>
                                </td>
                                <td class="w-full max-w-0 px-3 py-3 sm:w-auto sm:max-w-none sm:px-5">
                                    <a href="{{ route('posts.show', $post) }}" class="flex items-center gap-3 sm:max-w-xs xl:max-w-sm">
                                        <x-post-thumbnail :post="$post" size="size-11" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-slate-900 hover:text-indigo-600">
                                                {{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 70) ?: ($post->option('link') ?: 'Untitled') }}
                                            </span>
                                            <span class="text-xs text-slate-500">
                                                {{ $post->option('link') ? 'Shared link' : ($post->media_type ? ucfirst($post->media_type) : 'Text') }}
                                                @if ($post->usesShareMode()) · Share mode @endif
                                            </span>
                                            {{-- Phones: status, delivery and time under the title --}}
                                            <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 sm:hidden">
                                                <span class="rounded-full px-2 py-0.5 font-medium {{ $post->status->color() }}">{{ $post->status->label() }}</span>
                                                <span class="tabular-nums">{{ $post->published_targets_count }}/{{ $post->targets_count }}</span>
                                                @if ($post->failed_targets_count > 0)
                                                    <span class="text-red-600">✕ {{ $post->failed_targets_count }}</span>
                                                @endif
                                                <span class="whitespace-nowrap"><x-local-time :time="$post->scheduled_at ?? $post->created_at" /></span>
                                            </span>
                                        </span>
                                    </a>
                                </td>
                                <td class="hidden px-5 py-3 md:table-cell">
                                    <div class="flex -space-x-1">
                                        @foreach ($post->targets->pluck('socialAccount.platform')->filter()->unique() as $platform)
                                            <x-platform-badge :platform="$platform" class="ring-2 ring-white" />
                                        @endforeach
                                    </div>
                                </td>
                                <td class="hidden px-5 py-3 whitespace-nowrap lg:table-cell">
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
                                <td class="hidden px-5 py-3 sm:table-cell">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap {{ $post->status->color() }}">{{ $post->status->label() }}</span>
                                </td>
                                <td class="hidden px-5 py-3 whitespace-nowrap text-slate-600 md:table-cell">
                                    <x-local-time :time="$post->scheduled_at ?? $post->created_at" />
                                </td>
                                <td class="px-3 py-3 text-right sm:px-5">
                                    @unless ($post->isBusy())
                                        <form method="POST" action="{{ route('posts.bulk-destroy') }}"
                                              data-confirm="Delete this post from the app? It stays on the social networks." onsubmit="return confirm(this.dataset.confirm)">
                                            @csrf
                                            <input type="hidden" name="posts[]" value="{{ $post->id }}">
                                            <button class="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Delete" aria-label="Delete post {{ $post->id }}">
                                                <x-icon name="trash" class="size-4" />
                                            </button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
            <form method="GET" action="{{ route('posts.index') }}" class="flex items-center gap-2 text-sm text-slate-600">
                @foreach (array_filter(['status' => $filter, 'search' => $search, 'platform' => $platform?->value]) as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <label for="per_page">Rows per page</label>
                <select id="per_page" name="per_page" data-auto-submit class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                    @foreach ([10, 20, 50, 100] as $option)
                        <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <span class="ml-2 tabular-nums">Showing {{ $posts->firstItem() }}–{{ $posts->lastItem() }} of {{ $posts->total() }}</span>
            </form>
            <div>{{ $posts->onEachSide(1)->links() }}</div>
        </div>
    @endif
</x-layout>
