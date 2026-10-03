<x-layout title="System" wide>
    <div class="w-full space-y-6">
        @guest
            @if (session('success'))
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif
        @endguest

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold">Server health and maintenance</h2>
                <p class="text-sm text-slate-500">Everything you would normally do in a terminal.{{ $unlockedWithToken ? ' Unlocked with the access token for 30 minutes.' : '' }}</p>
            </div>
            @if ($unlockedWithToken)
                <form method="POST" action="{{ route('system.lock') }}">
                    @csrf
                    <button class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lock</button>
                </form>
            @endif
        </div>

        @if (session('command_output'))
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="mb-2 font-semibold">Output</h3>
                <pre class="max-h-80 overflow-auto rounded-lg bg-slate-900 p-3 text-xs whitespace-pre-wrap text-slate-100">{{ session('command_output') }}</pre>
            </section>
        @endif

        {{-- Network --}}
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Connection to Facebook, Google and TikTok</h3>
                    <p class="text-xs text-slate-500">Tests whether this server can reach each API, normally and with IPv4 only. Takes up to a minute.</p>
                </div>
                <a href="{{ route('system.show', ['network' => 1]) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Run network test</a>
            </div>

            @if ($networkChecks)
                @php($defaultFails = collect($networkChecks)->contains(fn ($row) => ! $row['default']['ok']))
                @php($ipv4Works = collect($networkChecks)->every(fn ($row) => $row['ipv4']['ok']))
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-slate-500 uppercase"><tr><th class="py-2 pr-4">API</th><th class="py-2 pr-4">Normal</th><th class="py-2">IPv4 only</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($networkChecks as $row)
                                <tr>
                                    <td class="py-2 pr-4 font-medium">{{ $row['label'] }}<div class="text-xs font-normal text-slate-400">{{ $row['url'] }}</div></td>
                                    @foreach (['default', 'ipv4'] as $mode)
                                        <td class="py-2 pr-4 text-xs">
                                            <span class="{{ $row[$mode]['ok'] ? 'text-green-700' : 'text-red-600' }} font-semibold">{{ $row[$mode]['ok'] ? '✓ Reachable' : '✕ Blocked' }}</span>
                                            <div class="text-slate-500">{{ $row[$mode]['detail'] }}</div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div @class(['mt-4 rounded-lg px-4 py-3 text-sm', 'bg-green-50 text-green-800' => ! $defaultFails, 'bg-amber-50 text-amber-900' => $defaultFails && $ipv4Works, 'bg-red-50 text-red-800' => $defaultFails && ! $ipv4Works])>
                    @if (! $defaultFails)
                        ✓ The server reaches every API. Connecting accounts should work.
                    @elseif ($ipv4Works)
                        <strong>The server's IPv6 is broken, but IPv4 works.</strong> Turn on "Force IPv4" below, then connect your accounts again.
                    @else
                        <strong>The hosting firewall blocks the ✕ servers above.</strong> Allow them in cPanel → <strong>Security → Outgoing Connections → Control Center</strong>.
                        Facebook and YouTube video files come from hundreds of servers, so allow the whole domains <strong>fbcdn.net</strong> and <strong>googlevideo.com</strong>
                        (wildcard <code>*.fbcdn.net</code> / <code>*.googlevideo.com</code>), not single names.
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('system.ipv4') }}" class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 text-sm">
                @csrf
                <input type="hidden" name="enabled" value="{{ $forcesIpv4 ? 0 : 1 }}">
                <span>Force IPv4: <strong class="{{ $forcesIpv4 ? 'text-green-700' : 'text-slate-600' }}">{{ $forcesIpv4 ? 'ON' : 'OFF' }}</strong></span>
                @if ($ipv4FromEnv)
                    <span class="text-xs text-slate-500">(switched on by HTTP_FORCE_IPV4 in .env)</span>
                @else
                    <button class="rounded-lg border border-slate-300 px-3 py-1.5 font-medium hover:bg-slate-50">{{ $forcesIpv4 ? 'Turn off' : 'Turn on' }}</button>
                @endif
            </form>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Server checks --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 font-semibold">Server checks</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($serverChecks as $check)
                        <li class="flex gap-3 py-2">
                            <span class="{{ $check['ok'] ? 'text-green-600' : 'text-red-600' }} font-bold">{{ $check['ok'] ? '✓' : '✕' }}</span>
                            <div class="min-w-0">
                                <div class="font-medium">{{ $check['label'] }}</div>
                                <div class="text-xs break-words text-slate-500">{{ $check['detail'] }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <div class="space-y-6">
                {{-- Actions --}}
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-1 font-semibold">Actions</h3>
                    <p class="mb-3 text-xs text-slate-500">Publishing queue: {{ $queue['pending'] }} waiting, {{ $queue['failed'] }} failed.</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (\App\Http\Controllers\SystemController::ACTIONS as $key => $action)
                            <form method="POST" action="{{ route('system.run') }}">
                                @csrf
                                <input type="hidden" name="action" value="{{ $key }}">
                                <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm font-medium hover:bg-slate-50">{{ $action['label'] }}</button>
                            </form>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-slate-500">After uploading new files: <strong>Update database</strong>, then <strong>Clear cache</strong>.</p>
                </section>

                {{-- Cron --}}
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-1 font-semibold">Cron job for cPanel</h3>
                    <p class="mb-2 text-xs text-slate-500">cPanel → Cron Jobs → Common settings: <em>Once per minute</em> → paste this command:</p>
                    <div class="flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1.5 text-xs" title="{{ $cronCommand }}">{{ $cronCommand }}</code>
                        <button type="button" data-copy="{{ $cronCommand }}" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">Copy</button>
                    </div>
                </section>

                {{-- Create user --}}
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold">Create a login</h3>
                    <form method="POST" action="{{ route('system.users') }}" class="grid gap-3 sm:grid-cols-3">
                        @csrf
                        <input name="name" value="{{ old('name') }}" required placeholder="Name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <input name="email" type="email" value="{{ old('email') }}" required placeholder="Email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <input name="password" type="password" required minlength="8" placeholder="Password (8+)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 sm:col-span-3">Create login</button>
                    </form>
                    @if ($errors->any())
                        <p class="mt-2 text-sm text-red-600">{{ $errors->first() }}</p>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-layout>
