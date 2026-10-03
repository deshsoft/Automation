<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Exceptions\PublishingException;
use App\Models\LiveStream;
use App\Models\LiveStreamTarget;
use App\Services\Live\FacebookLive;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LiveStreamController extends Controller
{
    public function index(Request $request): View
    {
        return view('live.index', [
            'pages' => $request->user()->socialAccounts()->active()->where('platform', Platform::Facebook)->orderBy('name')->get(),
            'liveStreams' => $request->user()->liveStreams()->withCount('targets')->latest()->limit(20)->get(),
        ]);
    }

    /**
     * Create a live video on every selected Page (or only on the main Page in
     * share mode) and show the stream keys.
     */
    public function store(Request $request, FacebookLive $facebookLive): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'accounts' => ['required', 'array', 'min:1', 'max:20'],
            'accounts.*' => [
                'integer',
                'distinct',
                Rule::exists('social_accounts', 'id')
                    ->where('user_id', $request->user()->id)
                    ->where('platform', Platform::Facebook->value)
                    ->where('is_active', true),
            ],
            'mode' => ['nullable', Rule::in(['all', 'share'])],
            'main_account_id' => ['nullable', 'required_if:mode,share', 'integer', Rule::in($request->input('accounts', []))],
            'share_message' => ['nullable', 'string', 'max:2000'],
        ], [
            'accounts.required' => 'Select at least one Facebook Page.',
            'main_account_id.required_if' => 'Choose the main Page that goes live.',
            'main_account_id.in' => 'The main Page must be one of the selected Pages.',
        ]);

        $shareMode = ($validated['mode'] ?? 'all') === 'share';

        if ($shareMode && count($validated['accounts']) < 2) {
            return back()->withInput()->withErrors(['main_account_id' => 'Select at least two Pages to share the live.']);
        }

        $liveStream = $request->user()->liveStreams()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'share_message' => $shareMode ? ($validated['share_message'] ?? null) : null,
            'status' => LiveStream::STATUS_LIVE,
        ]);

        $pages = $request->user()->socialAccounts()->whereIn('id', $validated['accounts'])->get();

        foreach ($pages as $page) {
            if ($shareMode && $page->id !== (int) $validated['main_account_id']) {
                $liveStream->targets()->create([
                    'social_account_id' => $page->id,
                    'role' => LiveStreamTarget::ROLE_SHARE,
                    'status' => LiveStreamTarget::STATUS_WAITING,
                ]);

                continue;
            }

            try {
                $live = $facebookLive->create($page, $liveStream);

                $liveStream->targets()->create([
                    'social_account_id' => $page->id,
                    'role' => LiveStreamTarget::ROLE_LIVE,
                    'status' => LiveStreamTarget::STATUS_READY,
                    'platform_live_id' => $live['id'],
                    'stream_url' => $live['stream_url'],
                    'permalink' => $live['permalink'],
                ]);
            } catch (PublishingException|RequestException|ConnectionException $exception) {
                $liveStream->targets()->create([
                    'social_account_id' => $page->id,
                    'role' => LiveStreamTarget::ROLE_LIVE,
                    'status' => LiveStreamTarget::STATUS_FAILED,
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
            }
        }

        if (! $liveStream->targets()->where('status', LiveStreamTarget::STATUS_READY)->exists()) {
            $liveStream->update(['status' => LiveStream::STATUS_ENDED, 'ended_at' => now()]);

            return redirect()->route('live.show', $liveStream)->with('error', 'The live video could not be created.');
        }

        return redirect()->route('live.show', $liveStream)->with('success', $shareMode
            ? 'Live created on the main Page. Start streaming, then click "Share to other Pages".'
            : 'Live videos created. Start streaming from OBS or your phone now.');
    }

    /**
     * Share the main Page's live video on the other Pages (also retries failed shares).
     */
    public function share(LiveStream $liveStream, FacebookLive $facebookLive): RedirectResponse
    {
        Gate::authorize('update', $liveStream);

        $liveStream->load('targets.socialAccount');
        $main = $liveStream->mainTarget();

        if (! $liveStream->isLive() || $main === null || $main->status !== LiveStreamTarget::STATUS_READY) {
            return back()->with('error', 'The main Page is not live, so there is nothing to share.');
        }

        $liveLink = $main->permalink ?: 'https://www.facebook.com/'.$main->platform_live_id;
        $shared = 0;

        $sharers = $liveStream->targets
            ->where('role', LiveStreamTarget::ROLE_SHARE)
            ->whereIn('status', [LiveStreamTarget::STATUS_WAITING, LiveStreamTarget::STATUS_FAILED]);

        foreach ($sharers as $target) {
            try {
                $post = $facebookLive->share($target->socialAccount, $liveLink, $liveStream->share_message);
                $target->update(['status' => LiveStreamTarget::STATUS_SHARED, 'permalink' => $post['permalink'], 'error' => null]);
                $shared++;
            } catch (PublishingException|RequestException|ConnectionException $exception) {
                $target->update(['status' => LiveStreamTarget::STATUS_FAILED, 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
            }
        }

        $failed = $sharers->count() - $shared;

        return back()->with($failed > 0 ? 'error' : 'success', $failed > 0
            ? "Shared on {$shared} Page(s), {$failed} failed. Click the button again to retry."
            : "Live shared on {$shared} Page(s).");
    }

    public function show(LiveStream $liveStream): View
    {
        Gate::authorize('view', $liveStream);

        $liveStream->load('targets.socialAccount');

        return view('live.show', ['liveStream' => $liveStream]);
    }

    /**
     * End the live video on every Page.
     */
    public function end(LiveStream $liveStream, FacebookLive $facebookLive): RedirectResponse
    {
        Gate::authorize('update', $liveStream);

        $problems = [];

        $broadcasts = $liveStream->targets()->with('socialAccount')
            ->where('role', LiveStreamTarget::ROLE_LIVE)
            ->where('status', LiveStreamTarget::STATUS_READY)
            ->get();

        foreach ($broadcasts as $target) {
            try {
                $facebookLive->end($target);
                $target->update(['status' => LiveStreamTarget::STATUS_ENDED]);
            } catch (PublishingException|RequestException|ConnectionException $exception) {
                $target->update(['status' => LiveStreamTarget::STATUS_ENDED, 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
                $problems[] = $target->socialAccount->name;
            }
        }

        $liveStream->update(['status' => LiveStream::STATUS_ENDED, 'ended_at' => now()]);

        if ($problems !== []) {
            return back()->with('error', 'Could not end the live on: '.implode(', ', $problems).'. Stop streaming in OBS/the app, and Facebook ends it automatically.');
        }

        return back()->with('success', 'Live ended on all Pages.');
    }
}
