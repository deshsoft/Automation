<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Jobs\PrepareImportedVideo;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\VideoDownload;
use App\Services\Publishing\PostDispatcher;
use App\Services\ThumbnailOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class PostController extends Controller
{
    /**
     * @var array<string, list<PostStatus>>
     */
    private const STATUS_FILTERS = [
        'scheduled' => [PostStatus::Scheduled],
        'publishing' => [PostStatus::Preparing, PostStatus::Publishing],
        'published' => [PostStatus::Published],
        'failed' => [PostStatus::Failed, PostStatus::PartiallyFailed],
    ];

    /**
     * @var list<int>
     */
    private const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('status'), self::STATUS_FILTERS) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE_OPTIONS, true) ? (int) $request->query('per_page') : 20;
        $platform = Platform::tryFrom((string) $request->query('platform'));

        $posts = $request->user()->posts()
            ->with('targets.socialAccount:id,platform')
            ->withCount([
                'targets',
                'targets as published_targets_count' => fn ($query) => $query->where('status', TargetStatus::Published),
                'targets as failed_targets_count' => fn ($query) => $query->where('status', TargetStatus::Failed),
            ])
            ->when($filter, fn ($query) => $query->whereIn('status', self::STATUS_FILTERS[$filter]))
            ->when($platform, fn ($query) => $query->whereHas('targets.socialAccount', fn ($query) => $query->where('platform', $platform)))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('caption', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')
                ->orWhere('options', 'like', '%'.$search.'%')))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $statusCounts = $request->user()->posts()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('posts.index', [
            'posts' => $posts,
            'filter' => $filter,
            'search' => $search,
            'perPage' => $perPage,
            'platform' => $platform,
            'tabCounts' => [
                null => $statusCounts->sum(),
                ...collect(self::STATUS_FILTERS)->map(fn (array $statuses) => collect($statuses)->sum(fn (PostStatus $status) => $statusCounts[$status->value] ?? 0))->all(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $accounts = $request->user()->socialAccounts()->active()->orderBy('platform')->orderBy('name')->get();

        return view('posts.create', ['accounts' => $accounts]);
    }

    public function store(StorePostRequest $request, PostDispatcher $dispatcher, ThumbnailOptimizer $thumbnailOptimizer): RedirectResponse
    {
        $mediaFiles = $request->mediaFiles();
        $media = $mediaFiles[0] ?? null;
        $scheduledAt = $request->filled('scheduled_at')
            ? Carbon::parse($request->input('scheduled_at'), config('app.display_timezone'))->utc()
            : null;

        $mediaPaths = array_map(fn ($file) => $file->store('media', 'public'), $mediaFiles);

        $post = DB::transaction(function () use ($request, $media, $mediaPaths, $scheduledAt, $thumbnailOptimizer) {
            $post = $request->user()->posts()->create([
                'title' => $request->input('title'),
                'caption' => $request->input('caption'),
                'media_path' => $mediaPaths[0] ?? null,
                'media_type' => $media ? (str_starts_with((string) $media->getMimeType(), 'video/') ? Post::MEDIA_VIDEO : Post::MEDIA_PHOTO) : null,
                'media_mime' => $media?->getMimeType(),
                'thumbnail_path' => $request->hasFile('thumbnail') ? $thumbnailOptimizer->store($request->file('thumbnail')) : null,
                'options' => [
                    ...$request->postOptions(),
                    // Several photos: all of them are posted (carousel / album / slideshow).
                    ...(count($mediaPaths) > 1 ? ['gallery' => $mediaPaths] : []),
                ],
                'share_from_account_id' => $request->usesShareMode() ? (int) $request->input('share_from_account_id') : null,
                'stagger_seconds' => (int) $request->input('stagger_seconds', 0),
                'fingerprint' => $request->fingerprint(),
                'status' => match (true) {
                    $request->filled('import_url') => PostStatus::Preparing,
                    $scheduledAt !== null => PostStatus::Scheduled,
                    default => PostStatus::Publishing,
                },
                'scheduled_at' => $scheduledAt,
            ]);

            if ($request->filled('import_url')) {
                $download = $request->user()->videoDownloads()->create([
                    'url' => $request->input('import_url'),
                    'source' => VideoDownload::sourceOf($request->input('import_url')),
                    'quality' => '1080',
                    'status' => VideoDownload::STATUS_QUEUED,
                ]);
                $post->update(['video_download_id' => $download->id, 'media_type' => Post::MEDIA_VIDEO]);
            }

            foreach ($request->validated('accounts') as $accountId) {
                $post->targets()->create(['social_account_id' => $accountId, 'status' => TargetStatus::Pending]);
            }

            return $post;
        });

        if ($post->status === PostStatus::Preparing) {
            PrepareImportedVideo::dispatch($post);

            return redirect()->route('posts.show', $post)->with('success', 'Downloading the video. It will be published automatically when ready.');
        }

        if ($scheduledAt === null) {
            $dispatcher->dispatch($post);

            return redirect()->route('posts.show', $post)->with('success', 'Publishing started.');
        }

        return redirect()->route('posts.show', $post)->with('success', 'Post scheduled.');
    }

    public function show(Post $post): View
    {
        Gate::authorize('view', $post);

        $post->load(['targets.socialAccount', 'shareFromAccount']);
        $post->targets->each->setRelation('post', $post);

        return view('posts.show', ['post' => $post]);
    }

    public function edit(Request $request, Post $post): View|RedirectResponse
    {
        Gate::authorize('update', $post);

        if (! $post->isEditable()) {
            return redirect()->route('posts.show', $post)->with('error', 'Only scheduled, cancelled or failed posts can be edited.');
        }

        $post->load('targets.socialAccount');

        return view('posts.edit', [
            'post' => $post,
            'accounts' => $request->user()->socialAccounts()->active()->orderBy('platform')->orderBy('name')->get(),
            'canChangeAccounts' => $post->canChangeAccounts(),
        ]);
    }

    /**
     * Save the changes, then keep the post as it is, schedule it, or publish
     * (retry the failed accounts) now.
     */
    public function update(UpdatePostRequest $request, Post $post, PostDispatcher $dispatcher): RedirectResponse
    {
        if (! $post->isEditable()) {
            return redirect()->route('posts.show', $post)->with('error', 'Only scheduled, cancelled or failed posts can be edited.');
        }

        $files = $request->mediaFiles();
        $options = $post->options ?? [];
        $captions = collect($request->input('captions', []))
            ->only(array_map(fn (Platform $platform) => $platform->value, Platform::cases()))
            ->map(fn ($caption) => trim((string) $caption))
            ->filter()
            ->all();

        $options['captions'] = $captions;
        $options['music_url'] = $request->input('music_url');
        $options['location_id'] = $request->input('location_id');
        $options['youtube'] = array_filter([
            ...($options['youtube'] ?? []),
            'tags' => collect(explode(',', (string) $request->input('youtube_tags')))->map(fn ($tag) => trim($tag))->filter()->unique()->values()->all(),
            'privacy' => $request->input('youtube_privacy'),
            'format' => $request->input('youtube_format'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        // The YouTube video made from photos must be made again with the new settings.
        if (filled($options['youtube_video_path'] ?? null)) {
            Storage::disk('public')->delete($options['youtube_video_path']);
            unset($options['youtube_video_path']);
        }

        $attributes = ['title' => $request->input('title'), 'caption' => $request->input('caption')];

        if ($files !== []) {
            $post->deleteMediaFiles();
            $paths = array_map(fn ($file) => $file->store('media', 'public'), $files);
            $isVideo = str_starts_with((string) $files[0]->getMimeType(), 'video/');
            unset($options['gallery']);

            if (count($paths) > 1) {
                $options['gallery'] = $paths;
            }

            $attributes += [
                'media_path' => $paths[0],
                'media_type' => $isVideo ? Post::MEDIA_VIDEO : Post::MEDIA_PHOTO,
                'media_mime' => $files[0]->getMimeType(),
                'thumbnail_path' => null,
            ];
        }

        $post->update([...$attributes, 'options' => array_filter($options, fn ($value) => $value !== null && $value !== '' && $value !== [])]);

        if ($request->has('accounts') && $post->canChangeAccounts()) {
            $selected = array_map('intval', $request->input('accounts'));
            $post->targets()->whereNotIn('social_account_id', $selected)->delete();

            foreach (array_diff($selected, $post->targets()->pluck('social_account_id')->all()) as $accountId) {
                $post->targets()->create(['social_account_id' => $accountId, 'status' => TargetStatus::Pending]);
            }
        }

        return $this->applyTiming($request, $post->fresh(), $dispatcher);
    }

    private function applyTiming(UpdatePostRequest $request, Post $post, PostDispatcher $dispatcher): RedirectResponse
    {
        $when = $request->input('when');

        if ($when === 'keep') {
            return redirect()->route('posts.show', $post)->with('success', 'Changes saved.');
        }

        // Failed accounts get another try with the edited content.
        $post->targets()->where('status', TargetStatus::Failed)->update(['status' => TargetStatus::Pending, 'error' => null, 'state' => null]);

        $scheduledAt = $when === 'schedule'
            ? Carbon::parse($request->input('scheduled_at'), config('app.display_timezone'))->utc()
            : null;
        $needsDownload = filled($post->option('import_url')) && ! $post->hasMedia() && $post->videoDownload !== null;

        if ($needsDownload) {
            $post->videoDownload->update(['status' => VideoDownload::STATUS_QUEUED, 'error' => null]);
            $post->update(['status' => PostStatus::Preparing, 'scheduled_at' => $scheduledAt]);
            PrepareImportedVideo::dispatch($post);
        } elseif ($scheduledAt !== null) {
            $post->update(['status' => PostStatus::Scheduled, 'scheduled_at' => $scheduledAt]);
        } else {
            $post->update(['status' => PostStatus::Publishing, 'scheduled_at' => null]);
            $dispatcher->dispatch($post);
        }

        return redirect()->route('posts.show', $post)->with('success', $scheduledAt !== null ? 'Changes saved and the post is scheduled.' : 'Changes saved. Publishing now.');
    }

    /**
     * Turn YouTube "photo post" items (which need a manual click) into
     * automatic Shorts or regular videos made from the photos.
     */
    public function youtubeAsVideo(Request $request, Post $post, PostDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('update', $post);

        $format = $request->validate(['format' => ['required', Rule::in(['shorts', 'video'])]])['format'];
        $manualTargets = $post->targets()->where('status', TargetStatus::Manual);

        if (! $manualTargets->exists()) {
            return back()->with('error', 'There is nothing waiting to be posted on YouTube.');
        }

        $options = $post->options ?? [];
        $options['youtube'] = [...($options['youtube'] ?? []), 'format' => $format];
        unset($options['youtube_video_path']);

        $manualTargets->update(['status' => TargetStatus::Pending, 'error' => null]);
        $post->update(['options' => $options, 'status' => PostStatus::Publishing]);
        $dispatcher->dispatch($post);

        return back()->with('success', 'Making the '.($format === 'shorts' ? 'Shorts' : 'video').' from the photos and uploading it to YouTube automatically.');
    }

    /**
     * Stop a scheduled post from going out, but keep it (it can be edited and scheduled again).
     */
    public function cancel(Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

        if ($post->status !== PostStatus::Scheduled) {
            return back()->with('error', 'Only scheduled posts can be cancelled.');
        }

        $post->update(['status' => PostStatus::Cancelled]);

        return back()->with('success', 'Scheduled post cancelled. You can edit and schedule it again.');
    }

    /**
     * All photos of a post as one ZIP, for posting them by hand (YouTube photo posts).
     */
    public function downloadPhotos(Post $post): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view', $post);

        $paths = array_filter($post->photoPaths(), fn (string $path) => Storage::disk('public')->exists($path));

        if ($paths === []) {
            return back()->with('error', 'This post has no photos any more (they are deleted after a few days, see Settings).');
        }

        if (count($paths) === 1) {
            return response()->download(Storage::disk('public')->path(reset($paths)), 'post-'.$post->id.'.jpg');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'photos');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach (array_values($paths) as $index => $path) {
            $zip->addFile(Storage::disk('public')->path($path), sprintf('photo-%02d.%s', $index + 1, pathinfo($path, PATHINFO_EXTENSION)));
        }

        $zip->close();

        return response()->download($zipPath, 'post-'.$post->id.'-photos.zip')->deleteFileAfterSend();
    }

    /**
     * The user posted a "Ready to post" item by hand (e.g. a YouTube photo post).
     */
    public function markPosted(Request $request, PostTarget $target): RedirectResponse
    {
        Gate::authorize('update', $target->post);

        $validated = $request->validate(['permalink' => ['nullable', 'url:http,https', 'max:500']]);

        if ($target->status !== TargetStatus::Manual) {
            return back()->with('error', 'Only "Ready to post" items can be marked as posted.');
        }

        $target->update([
            'status' => TargetStatus::Published,
            'permalink' => $validated['permalink'] ?? null,
            'published_at' => now(),
        ]);
        $target->post->refreshStatus();

        return back()->with('success', $target->socialAccount->name.': marked as posted.');
    }

    public function retry(Post $post, PostDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('update', $post);

        $count = $dispatcher->retryFailed($post);

        return back()->with('success', "Retrying {$count} account(s).");
    }

    /**
     * Cancel a scheduled post, or delete a finished one.
     */
    /**
     * Delete several posts from the list at once. Posts that are being
     * downloaded or published right now are skipped.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:100'],
            'posts.*' => ['integer'],
        ], ['posts.required' => 'Select at least one post.']);

        $posts = $request->user()->posts()->whereIn('id', $validated['posts'])->get();

        $posts->each(function (Post $post) {
            $post->deleteMediaFiles();
            $post->delete();
        });

        return back()->with('success', "Deleted {$posts->count()} post(s).");
    }

    public function destroy(Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        // Any time, even while publishing: queued work for a deleted post is dropped.
        $post->deleteMediaFiles();
        $post->delete();

        return redirect()->route('posts.index')->with('success', 'Post deleted.');
    }
}
