<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadVideo;
use App\Models\VideoDownload;
use App\Services\Downloading\VideoDownloader;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VideoDownloadController extends Controller
{
    public function index(Request $request, VideoDownloader $downloader): View
    {
        $downloads = $request->user()->videoDownloads()->whereDoesntHave('post')->latest()->limit(50)->get();

        return view('downloads.index', [
            'downloads' => $downloads,
            'isWorking' => $downloads->contains(fn (VideoDownload $download) => ! $download->isFinished()),
            'isInstalled' => $downloader->isInstalled(),
            'hasFfmpeg' => $downloader->ffmpeg() !== null,
        ]);
    }

    /**
     * Queue one download per link (one link per line).
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'urls' => collect(preg_split('/\s+/', (string) $request->input('urls')))->filter()->unique()->values()->all(),
        ]);

        $validated = $request->validate([
            'urls' => ['required', 'array', 'min:1', 'max:10'],
            'urls.*' => [
                'url:http,https',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (VideoDownload::sourceOf((string) $value) === null) {
                        $fail("Only YouTube and Facebook links are supported: {$value}");
                    }
                },
            ],
            'quality' => ['required', Rule::in(array_keys(VideoDownload::QUALITIES))],
        ], [
            'urls.required' => 'Paste at least one YouTube or Facebook video link.',
            'urls.max' => 'Paste at most 10 links at a time.',
            'urls.*.url' => 'This is not a valid link: :input',
        ]);

        foreach ($validated['urls'] as $url) {
            $download = $request->user()->videoDownloads()->create([
                'url' => $url,
                'source' => VideoDownload::sourceOf($url),
                'quality' => $validated['quality'],
                'status' => VideoDownload::STATUS_QUEUED,
            ]);

            DownloadVideo::dispatch($download);
        }

        return redirect()->route('downloads.index')->with('success', count($validated['urls']) === 1
            ? 'Download started. The video appears below when it is ready.'
            : count($validated['urls']).' downloads started. The videos appear below when they are ready.');
    }

    /**
     * Send the downloaded file to the browser.
     */
    public function file(VideoDownload $download): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view', $download);

        if (! $download->hasFile()) {
            return back()->with('error', 'This file is not available any more. Download the video again.');
        }

        // BinaryFileResponse sends the file in small chunks; streaming with fpassthru()
        // ran out of memory on large videos.
        return response()->download(Storage::disk('local')->path($download->file_path), $download->downloadName());
    }

    public function retry(VideoDownload $download): RedirectResponse
    {
        Gate::authorize('delete', $download);

        if ($download->status !== VideoDownload::STATUS_FAILED) {
            return back();
        }

        $download->update(['status' => VideoDownload::STATUS_QUEUED, 'error' => null]);
        DownloadVideo::dispatch($download);

        return back()->with('success', 'Trying the download again.');
    }

    public function destroy(VideoDownload $download): RedirectResponse
    {
        Gate::authorize('delete', $download);

        $download->deleteFile();
        $download->delete();

        return back()->with('success', 'Download deleted.');
    }
}
