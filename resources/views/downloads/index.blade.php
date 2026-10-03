<x-layout title="Download video" :refresh="$isWorking">
    <p class="mb-6 text-sm text-slate-600">
        Paste YouTube or Facebook video links (one per line). The videos are saved on the server, then you download them here.
        Only download videos that you own or have permission to use.
    </p>

    @unless ($isInstalled)
        <div class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <x-icon name="warning" class="size-5 shrink-0" />
            <span>yt-dlp was not found. Run <code class="rounded bg-amber-100 px-1">php artisan downloads:install</code> on the server, or set <code class="rounded bg-amber-100 px-1">YTDLP_PATH</code> in .env.</span>
        </div>
    @endunless

    <form method="POST" action="{{ route('downloads.store') }}" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label for="urls" class="mb-1 block font-semibold">Video links</label>
            <textarea id="urls" name="urls" rows="3" required
                      placeholder="https://www.youtube.com/watch?v=...&#10;https://www.facebook.com/watch/?v=..."
                      class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm focus:border-indigo-500 focus:outline-none">{{ is_array(old('urls')) ? implode("\n", old('urls')) : old('urls') }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Up to 10 links. Facebook: public videos and Reels. YouTube: videos and Shorts.</p>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label for="quality" class="mb-1 block text-sm font-medium">Quality</label>
                <select id="quality" name="quality" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @foreach (\App\Models\VideoDownload::QUALITIES as $value => $label)
                        <option value="{{ $value }}" @selected(old('quality', '720') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 font-medium text-white hover:bg-indigo-700">
                <x-icon name="download" class="size-4" /> Download
            </button>
        </div>

        @unless ($hasFfmpeg)
            <p class="text-xs text-amber-700">ffmpeg is not installed, so YouTube videos may only come in low quality (360p) and audio is not converted to MP3. See README.</p>
        @endunless
    </form>

    @if ($downloads->isNotEmpty())
        <h2 class="mt-10 mb-3 text-lg font-semibold">Your downloads</h2>
        <div class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            @foreach ($downloads as $download)
                <div class="flex flex-wrap items-center gap-4 px-5 py-3 sm:flex-nowrap">
                    @if ($download->thumbnail_url)
                        <img src="{{ $download->thumbnail_url }}" alt="" class="h-12 w-20 shrink-0 rounded object-cover" referrerpolicy="no-referrer" loading="lazy">
                    @else
                        <div class="flex h-12 w-20 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-400">
                            <x-icon name="video" />
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $download->title ?: $download->url }}</div>
                        <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                            <span>{{ $download->source === \App\Models\VideoDownload::SOURCE_YOUTUBE ? 'YouTube' : 'Facebook' }}</span>
                            <span>· {{ \App\Models\VideoDownload::QUALITIES[$download->quality] ?? $download->quality }}</span>
                            @if ($download->duration)
                                <span>· {{ gmdate($download->duration >= 3600 ? 'G:i:s' : 'i:s', $download->duration) }}</span>
                            @endif
                            @if ($download->file_size)
                                <span>· {{ \Illuminate\Support\Number::fileSize($download->file_size, 1) }}</span>
                            @endif
                            <span>· <x-local-time :time="$download->created_at" /></span>
                        </div>
                        @if ($download->error)
                            <div class="mt-1 text-xs break-words text-red-700">{{ $download->error }}</div>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @switch($download->status)
                            @case(\App\Models\VideoDownload::STATUS_COMPLETED)
                                @if ($download->hasFile())
                                    <a href="{{ route('downloads.file', $download) }}" class="inline-flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                                        <x-icon name="download" class="size-4" /> Save
                                    </a>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">File deleted</span>
                                @endif
                                @break
                            @case(\App\Models\VideoDownload::STATUS_FAILED)
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Failed</span>
                                <form method="POST" action="{{ route('downloads.retry', $download) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Retry</button>
                                </form>
                                @break
                            @case(\App\Models\VideoDownload::STATUS_DOWNLOADING)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Downloading…</span>
                                @break
                            @default
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">Waiting</span>
                        @endswitch

                        @if ($download->isFinished())
                            <form method="POST" action="{{ route('downloads.destroy', $download) }}" onsubmit="return confirm('Delete this download?')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" title="Delete" aria-label="Delete">
                                    <x-icon name="close" class="size-4" />
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-slate-500">Files are deleted from the server after {{ config('services.downloader.keep_days') }} day(s).</p>
    @endif
</x-layout>
