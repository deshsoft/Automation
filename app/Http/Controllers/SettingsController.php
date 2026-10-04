<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', [
            'settings' => collect(Setting::defaults())->mapWithKeys(fn (int $default, string $key) => [$key => Setting::integer($key)]),
            'usage' => [
                'Post photos and videos' => $this->folderSize(Storage::disk('public')->path('media')),
                'Downloaded videos' => $this->folderSize(Storage::disk('local')->path('downloads')),
                'Log files' => $this->folderSize(storage_path('logs')),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'media_keep_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'posts_keep_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'downloads_keep_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::put($key, (int) $value);
        }

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Run all clean-up jobs now instead of waiting for the nightly run.
     */
    public function cleanUp(): RedirectResponse
    {
        $output = [];

        foreach (['posts:prune', 'posts:prune-media', 'downloads:prune'] as $command) {
            Artisan::call($command);
            $output[] = trim(Artisan::output());
        }

        return back()->with('success', 'Clean-up finished. '.implode(' ', array_filter($output)));
    }

    private function folderSize(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        return collect(File::allFiles($path))->sum(fn ($file) => $file->getSize());
    }
}
