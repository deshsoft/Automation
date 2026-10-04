<x-layout title="Settings">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('settings.update') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
            @csrf
            @method('PUT')
            <div>
                <h2 class="text-lg font-semibold">Automatic clean-up</h2>
                <p class="text-sm text-slate-500">Runs every night to keep the server's disk space free. Posts stay on Facebook, YouTube and TikTok; only this app's copy is removed.</p>
            </div>

            @foreach ([
                'media_keep_days' => ['Delete photo and video files after', 'Files of finished posts are removed after this many days. The post history stays.', 1],
                'posts_keep_days' => ['Delete whole posts after', 'Finished posts (published, failed or cancelled) are removed from the Posts list. 0 = never delete.', 0],
                'downloads_keep_days' => ['Delete downloaded videos after', 'Videos on the "Download video" page.', 1],
            ] as $key => [$label, $hint, $minimum])
                <div>
                    <label for="{{ $key }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                    <div class="flex items-center gap-2">
                        <input id="{{ $key }}" name="{{ $key }}" type="number" min="{{ $minimum }}" max="3650" required value="{{ old($key, $settings[$key]) }}"
                               class="w-28 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                        <span class="text-sm text-slate-600">days</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                    @error($key) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endforeach

            <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700">Save settings</button>
        </form>

        <section class="h-fit rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Disk usage</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($usage as $label => $bytes)
                    <li class="flex justify-between gap-3">
                        <span class="text-slate-600">{{ $label }}</span>
                        <span class="font-medium tabular-nums">{{ \Illuminate\Support\Number::fileSize($bytes, 1) }}</span>
                    </li>
                @endforeach
                <li class="flex justify-between gap-3 border-t border-slate-100 pt-2 font-semibold">
                    <span>Total</span>
                    <span class="tabular-nums">{{ \Illuminate\Support\Number::fileSize(array_sum($usage), 1) }}</span>
                </li>
            </ul>
            <form method="POST" action="{{ route('settings.clean-up') }}" class="mt-4"
                  data-confirm="Run the clean-up now with the saved settings?" onsubmit="return confirm(this.dataset.confirm)">
                @csrf
                <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">🧹 Clean up now</button>
            </form>
        </section>
    </div>
</x-layout>
