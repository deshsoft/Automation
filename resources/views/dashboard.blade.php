<x-layout title="Dashboard" wide>
    {{-- Greeting --}}
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">
                {{ now(config('app.display_timezone'))->hour < 12 ? 'Good morning' : (now(config('app.display_timezone'))->hour < 17 ? 'Good afternoon' : 'Good evening') }}, {{ auth()->user()->name }}
            </h2>
            <p class="mt-1 text-sm text-slate-500">Here is what is happening across your Pages and channels.</p>
        </div>
        <div class="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium">
            @if ($schedulerRunning)
                <span class="size-2 rounded-full bg-green-500"></span> Publishing engine running
            @else
                <span class="size-2 rounded-full bg-red-500"></span> Publishing engine stopped
            @endif
        </div>
    </div>

    @if ($activeLive)
        <a href="{{ route('live.show', $activeLive) }}" class="mb-6 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-5 py-4 hover:bg-red-100">
            <span class="relative flex size-3"><span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex size-3 rounded-full bg-red-600"></span></span>
            <span class="text-sm font-medium text-red-800">You are live: {{ $activeLive->title }}</span>
            <span class="ml-auto text-sm font-medium text-red-700">Manage →</span>
        </a>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-5">
        <x-stat-card label="Published" :value="number_format($stats['published'])" icon="check" tone="green" hint="Account deliveries, last 30 days" />
        <x-stat-card label="Failed" :value="number_format($stats['failed'])" icon="x-circle" tone="red" hint="Last 30 days" />
        <x-stat-card label="Success rate" :value="$stats['success_rate'] === null ? '—' : $stats['success_rate'].'%'" icon="chart" tone="indigo" hint="Published ÷ all attempts" />
        <x-stat-card label="Scheduled" :value="number_format($stats['scheduled'])" icon="clock" tone="amber" hint="Posts waiting to go out" />
        <x-stat-card label="Accounts" :value="number_format($stats['accounts'])" icon="accounts" tone="slate" hint="Active Pages and channels" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Activity chart --}}
        @php($chartMax = max(1, collect($chart)->max(fn ($day) => $day['published'] + $day['failed'])))
        @php($gridStep = max(1, (int) ceil($chartMax / 4)))
        @php($axisMax = $gridStep * 4)
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Publishing activity</h3>
                    <p class="text-xs text-slate-500">Account deliveries per day, last 14 days</p>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-600">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background:#0ca30c"></span> ✓ Published</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background:#d03b3b"></span> ✕ Failed</span>
                </div>
            </div>

            <div class="flex gap-2">
                {{-- Y axis --}}
                <div class="flex h-52 flex-col justify-between pb-0 text-right text-[11px] text-slate-400 tabular-nums">
                    @for ($line = 4; $line >= 0; $line--)
                        <span class="-translate-y-1/2 leading-none">{{ $gridStep * $line }}</span>
                    @endfor
                </div>

                <div class="min-w-0 flex-1">
                    <div class="relative h-52">
                        {{-- Grid lines --}}
                        @for ($line = 0; $line <= 4; $line++)
                            <div class="absolute inset-x-0 border-t {{ $line === 0 ? 'border-slate-300' : 'border-dashed border-slate-100' }}" style="bottom: {{ $line * 25 }}%"></div>
                        @endfor

                        {{-- Bars --}}
                        <div class="absolute inset-0 flex items-end gap-1 sm:gap-2">
                            @foreach ($chart as $day)
                                @php($total = $day['published'] + $day['failed'])
                                <div class="group relative flex h-full flex-1 items-end justify-center">
                                    <div class="flex w-full max-w-7 flex-col-reverse gap-[2px]" style="height: {{ $total / $axisMax * 100 }}%">
                                        @if ($day['published'] > 0)
                                            <div @class(['w-full', 'rounded-t' => $day['failed'] === 0]) style="background:#0ca30c; height: {{ $day['published'] / max($total, 1) * 100 }}%"></div>
                                        @endif
                                        @if ($day['failed'] > 0)
                                            <div class="w-full rounded-t" style="background:#d03b3b; height: {{ $day['failed'] / max($total, 1) * 100 }}%"></div>
                                        @endif
                                    </div>
                                    {{-- Hover target and tooltip --}}
                                    <div class="absolute inset-0 cursor-default rounded group-hover:bg-slate-900/5"></div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden w-36 -translate-x-1/2 rounded-lg bg-slate-900 px-3 py-2 text-xs text-white shadow-lg group-hover:block">
                                        <div class="mb-1 font-semibold">{{ $day['date']->format('D, d M') }}</div>
                                        <div class="flex justify-between"><span>✓ Published</span><span class="tabular-nums">{{ $day['published'] }}</span></div>
                                        <div class="flex justify-between"><span>✕ Failed</span><span class="tabular-nums">{{ $day['failed'] }}</span></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- X axis --}}
                    <div class="mt-2 flex gap-1 text-center text-[11px] text-slate-400 sm:gap-2">
                        @foreach ($chart as $index => $day)
                            <div class="flex-1 whitespace-nowrap">{{ $index % 2 === 1 || $loop->last ? $day['date']->format('j M') : '' }}</div>
                        @endforeach
                    </div>
                </div>
            </div>

            <details class="mt-4 text-sm">
                <summary class="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-700">View as table</summary>
                <table class="mt-2 w-full text-xs">
                    <thead class="text-left text-slate-500"><tr><th class="py-1">Day</th><th class="py-1 text-right">Published</th><th class="py-1 text-right">Failed</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach (array_reverse($chart) as $day)
                            <tr><td class="py-1">{{ $day['date']->format('D, d M Y') }}</td><td class="py-1 text-right tabular-nums">{{ $day['published'] }}</td><td class="py-1 text-right tabular-nums">{{ $day['failed'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        {{-- Accounts by platform --}}
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="font-semibold">Connected accounts</h3>
                <a href="{{ route('accounts.index') }}" class="text-xs font-medium text-indigo-600 hover:underline">Manage</a>
            </div>
            <ul class="space-y-3">
                @foreach (\App\Enums\Platform::cases() as $platform)
                    <li class="flex items-center gap-3">
                        <x-platform-badge :platform="$platform" />
                        <span class="flex-1 text-sm">{{ $platform->label() }}</span>
                        <span class="text-sm font-semibold tabular-nums">{{ $accountsByPlatform[$platform->value] ?? 0 }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-5 grid grid-cols-2 gap-2">
                <a href="{{ route('posts.create') }}" class="rounded-lg bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700">Create post</a>
                <a href="{{ route('live.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">● Go Live</a>
            </div>
        </section>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Recent posts --}}
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="font-semibold">Recent posts</h3>
                <a href="{{ route('posts.index') }}" class="text-xs font-medium text-indigo-600 hover:underline">View all</a>
            </div>
            @forelse ($recentPosts as $post)
                <a href="{{ route('posts.show', $post) }}" class="flex items-center gap-4 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50">
                    <x-post-thumbnail :post="$post" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 70) ?: ($post->option('link') ?: 'Untitled') }}</div>
                        <div class="text-xs text-slate-500"><x-local-time :time="$post->scheduled_at ?? $post->created_at" /> · {{ $post->published_targets_count }}/{{ $post->targets_count }} published</div>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $post->status->color() }}">{{ $post->status->label() }}</span>
                </a>
            @empty
                <div class="px-5 py-10 text-center text-sm text-slate-500">No posts yet. <a href="{{ route('posts.create') }}" class="text-indigo-600 underline">Create your first post</a>.</div>
            @endforelse
        </section>

        <div class="space-y-6">
            {{-- Needs attention --}}
            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-4">
                    <x-icon name="warning" class="size-5 text-red-500" />
                    <h3 class="font-semibold">Needs attention</h3>
                </div>
                @forelse ($needsAttention as $post)
                    <a href="{{ route('posts.show', $post) }}" class="block border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50">
                        <div class="truncate text-sm font-medium">{{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 50) ?: 'Untitled' }}</div>
                        <div class="text-xs text-red-600">✕ {{ $post->failed_targets_count }} account(s) failed · open to retry</div>
                    </a>
                @empty
                    <div class="px-5 py-6 text-center text-sm text-slate-500">✓ Nothing failed in the last 7 days.</div>
                @endforelse
            </section>

            {{-- Upcoming --}}
            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-4">
                    <x-icon name="clock" class="size-5 text-amber-600" />
                    <h3 class="font-semibold">Upcoming</h3>
                </div>
                @forelse ($upcoming as $post)
                    <a href="{{ route('posts.show', $post) }}" class="block border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50">
                        <div class="truncate text-sm font-medium">{{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 50) ?: 'Untitled' }}</div>
                        <div class="text-xs text-slate-500"><x-local-time :time="$post->scheduled_at" /> · {{ $post->targets_count }} account(s)</div>
                    </a>
                @empty
                    <div class="px-5 py-6 text-center text-sm text-slate-500">No scheduled posts.</div>
                @endforelse
            </section>
        </div>
    </div>
</x-layout>
