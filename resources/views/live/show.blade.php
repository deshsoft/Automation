<x-layout title="Live">
    @php($readyTargets = $liveStream->targets->where('status', \App\Models\LiveStreamTarget::STATUS_READY))
    @php($mainTarget = $liveStream->mainTarget())
    @php($pendingShares = $liveStream->targets->where('role', \App\Models\LiveStreamTarget::ROLE_SHARE)->whereIn('status', [\App\Models\LiveStreamTarget::STATUS_WAITING, \App\Models\LiveStreamTarget::STATUS_FAILED]))

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ $liveStream->title }}</h1>
            @if ($liveStream->isLive())
                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">● Live</span>
            @else
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">Ended</span>
            @endif
        </div>
        @if ($liveStream->isLive())
            <form method="POST" action="{{ route('live.end', $liveStream) }}" data-confirm="End the live on all Pages?" onsubmit="return confirm(this.dataset.confirm)">
                @csrf
                <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">■ End live on all Pages</button>
            </form>
        @endif
    </div>

    @if ($liveStream->isLive() && $pendingShares->isNotEmpty() && $mainTarget?->status === \App\Models\LiveStreamTarget::STATUS_READY)
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm text-indigo-900">
                <strong>Start streaming first</strong>, then share. If you share before the video starts, people see an empty live.
            </p>
            <form method="POST" action="{{ route('live.share', $liveStream) }}">
                @csrf
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    ↗ Share to other Pages ({{ $pendingShares->count() }})
                </button>
            </form>
        </div>
    @endif

    <div class="space-y-3">
        @foreach ($liveStream->targets->sortBy(fn ($target) => $target->role === \App\Models\LiveStreamTarget::ROLE_LIVE ? 0 : 1) as $target)
            <section class="rounded-lg border border-gray-200 bg-white p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-semibold">{{ $target->socialAccount->name }}</span>
                        @if ($liveStream->usesShareMode())
                            <span class="ml-1 text-xs text-gray-500">{{ $target->role === \App\Models\LiveStreamTarget::ROLE_LIVE ? '· Main Page (broadcasts)' : '· Shares the live' }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        @if ($target->permalink)
                            <a href="{{ $target->permalink }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:underline">
                                {{ $target->role === \App\Models\LiveStreamTarget::ROLE_SHARE ? 'View shared post ↗' : 'View live ↗' }}
                            </a>
                        @endif
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-green-100 text-green-800' => $target->status === \App\Models\LiveStreamTarget::STATUS_READY,
                            'bg-red-100 text-red-800' => $target->status === \App\Models\LiveStreamTarget::STATUS_FAILED,
                            'bg-gray-100 text-gray-700' => in_array($target->status, [\App\Models\LiveStreamTarget::STATUS_ENDED, \App\Models\LiveStreamTarget::STATUS_WAITING], true),
                            'bg-indigo-100 text-indigo-800' => $target->status === \App\Models\LiveStreamTarget::STATUS_SHARED,
                        ])>{{ ucfirst($target->status) }}</span>
                    </div>
                </div>

                @if ($target->error)
                    <p class="text-sm text-red-600">{{ $target->error }}</p>
                @endif

                @if ($liveStream->isLive() && $target->status === \App\Models\LiveStreamTarget::STATUS_READY)
                    <dl class="grid gap-2 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt class="text-gray-500">Server</dt>
                        <dd class="flex items-center gap-2">
                            <code class="min-w-0 flex-1 truncate rounded bg-gray-100 px-2 py-1">{{ $target->server() }}</code>
                            <button type="button" data-copy="{{ $target->server() }}" class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">Copy</button>
                        </dd>

                        <dt class="text-gray-500">Stream key</dt>
                        <dd class="flex items-center gap-2">
                            <code class="min-w-0 flex-1 truncate rounded bg-gray-100 px-2 py-1" data-secret="{{ $target->streamKey() }}">••••••••••••••••</code>
                            <button type="button" data-reveal class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">Show</button>
                            <button type="button" data-copy="{{ $target->streamKey() }}" class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">Copy</button>
                        </dd>

                        <dt class="text-gray-500">Full URL <span class="text-xs">(phone)</span></dt>
                        <dd class="flex items-center gap-2">
                            <code class="min-w-0 flex-1 truncate rounded bg-gray-100 px-2 py-1">{{ $target->server() }}••••••</code>
                            <button type="button" data-copy="{{ $target->server().$target->streamKey() }}" class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">Copy</button>
                        </dd>
                    </dl>
                @endif
            </section>
        @endforeach
    </div>

    @if ($liveStream->isLive() && $readyTargets->isNotEmpty())
        <div class="mt-8 grid gap-4 md:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-5 text-sm">
                <h2 class="mb-2 font-semibold">💻 From a computer (OBS Studio)</h2>
                <ol class="list-inside list-decimal space-y-1 text-gray-700">
                    <li>Install <a href="https://obsproject.com" target="_blank" rel="noopener" class="text-indigo-600 underline">OBS Studio</a> and the free <a href="https://github.com/sorayuki/obs-multi-rtmp/releases" target="_blank" rel="noopener" class="text-indigo-600 underline">Multiple RTMP outputs</a> plugin.</li>
                    <li>In the <strong>Multiple output</strong> panel, click <strong>Add new target</strong> once per Page that broadcasts.</li>
                    <li>For each, paste that Page's <strong>Server</strong> and <strong>Stream key</strong> from above.</li>
                    <li>Click <strong>Start all</strong>. Every Page goes live within a few seconds.</li>
                </ol>
                <p class="mt-2 text-xs text-gray-500">Only one Page? Use OBS → Settings → Stream → Service: <em>Custom</em>, without the plugin.</p>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 text-sm">
                <h2 class="mb-2 font-semibold">📱 From a phone (Larix Broadcaster)</h2>
                <ol class="list-inside list-decimal space-y-1 text-gray-700">
                    <li>Install the free <strong>Larix Broadcaster</strong> app (Android / iPhone).</li>
                    <li>⚙️ Settings → <strong>Connections</strong> → <strong>New connection</strong>, once per Page.</li>
                    <li>Paste that Page's <strong>Full URL</strong> into the URL field and save.</li>
                    <li>Tick all the connections, go back and press the red record button.</li>
                </ol>
                <p class="mt-2 text-xs text-gray-500">Mobile data can usually handle only 2–3 Pages at once. Use good Wi-Fi for more.</p>
            </section>
        </div>

        <p class="mt-4 text-xs text-gray-500">Keep stream keys secret: anyone with a key can broadcast on that Page. When you finish, click <strong>End live on all Pages</strong>.</p>
    @endif
</x-layout>
