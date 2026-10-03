<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Http\Requests\StorePostRequest;
use App\Models\Post;
use App\Services\Publishing\PostDispatcher;
use App\Services\ThumbnailOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PostController extends Controller
{
    /**
     * @var array<string, list<PostStatus>>
     */
    private const STATUS_FILTERS = [
        'scheduled' => [PostStatus::Scheduled],
        'publishing' => [PostStatus::Publishing],
        'published' => [PostStatus::Published],
        'failed' => [PostStatus::Failed, PostStatus::PartiallyFailed],
    ];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('status'), self::STATUS_FILTERS) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));

        $posts = $request->user()->posts()
            ->with('targets.socialAccount:id,platform')
            ->withCount([
                'targets',
                'targets as published_targets_count' => fn ($query) => $query->where('status', TargetStatus::Published),
                'targets as failed_targets_count' => fn ($query) => $query->where('status', TargetStatus::Failed),
            ])
            ->when($filter, fn ($query) => $query->whereIn('status', self::STATUS_FILTERS[$filter]))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('caption', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $statusCounts = $request->user()->posts()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('posts.index', [
            'posts' => $posts,
            'filter' => $filter,
            'search' => $search,
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
        $media = $request->file('media');
        $scheduledAt = $request->filled('scheduled_at')
            ? Carbon::parse($request->input('scheduled_at'), config('app.display_timezone'))->utc()
            : null;

        $post = DB::transaction(function () use ($request, $media, $scheduledAt, $thumbnailOptimizer) {
            $post = $request->user()->posts()->create([
                'title' => $request->input('title'),
                'caption' => $request->input('caption'),
                'media_path' => $media?->store('media', 'public'),
                'media_type' => $media ? (str_starts_with((string) $media->getMimeType(), 'video/') ? Post::MEDIA_VIDEO : Post::MEDIA_PHOTO) : null,
                'media_mime' => $media?->getMimeType(),
                'thumbnail_path' => $request->hasFile('thumbnail') ? $thumbnailOptimizer->store($request->file('thumbnail')) : null,
                'options' => $request->postOptions(),
                'share_from_account_id' => $request->usesShareMode() ? (int) $request->input('share_from_account_id') : null,
                'stagger_seconds' => (int) $request->input('stagger_seconds', 0),
                'status' => $scheduledAt ? PostStatus::Scheduled : PostStatus::Publishing,
                'scheduled_at' => $scheduledAt,
            ]);

            foreach ($request->validated('accounts') as $accountId) {
                $post->targets()->create(['social_account_id' => $accountId, 'status' => TargetStatus::Pending]);
            }

            return $post;
        });

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

    public function retry(Post $post, PostDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('update', $post);

        $count = $dispatcher->retryFailed($post);

        return back()->with('success', "Retrying {$count} account(s).");
    }

    /**
     * Cancel a scheduled post, or delete a finished one.
     */
    public function destroy(Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        if ($post->status === PostStatus::Scheduled) {
            $post->update(['status' => PostStatus::Cancelled]);

            return back()->with('success', 'Scheduled post cancelled.');
        }

        if ($post->status === PostStatus::Publishing) {
            return back()->with('error', 'This post is publishing right now. Wait until it finishes.');
        }

        $post->deleteMediaFiles();
        $post->delete();

        return redirect()->route('posts.index')->with('success', 'Post deleted.');
    }
}
