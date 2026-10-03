<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\LiveStream;
use App\Models\PostTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const CHART_DAYS = 14;

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        if ($user === null) {
            return view('home');
        }

        $timezone = config('app.display_timezone');
        $since = now()->subDays(30);

        $recentTargets = PostTarget::query()
            ->whereHas('post', fn ($query) => $query->where('user_id', $user->id))
            ->whereIn('status', [TargetStatus::Published, TargetStatus::Failed])
            ->where('updated_at', '>=', now($timezone)->startOfDay()->subDays(self::CHART_DAYS - 1)->utc())
            ->get(['status', 'published_at', 'updated_at']);

        $publishedLast30 = PostTarget::query()
            ->whereHas('post', fn ($query) => $query->where('user_id', $user->id))
            ->where('status', TargetStatus::Published)
            ->where('published_at', '>=', $since)
            ->count();

        $failedLast30 = PostTarget::query()
            ->whereHas('post', fn ($query) => $query->where('user_id', $user->id))
            ->where('status', TargetStatus::Failed)
            ->where('updated_at', '>=', $since)
            ->count();

        $attempts = $publishedLast30 + $failedLast30;
        $lastSchedulerRun = Cache::get('scheduler:last-run');

        return view('dashboard', [
            'stats' => [
                'published' => $publishedLast30,
                'failed' => $failedLast30,
                'success_rate' => $attempts > 0 ? (int) round($publishedLast30 / $attempts * 100) : null,
                'scheduled' => $user->posts()->where('status', PostStatus::Scheduled)->count(),
                'accounts' => $user->socialAccounts()->active()->count(),
            ],
            'accountsByPlatform' => $user->socialAccounts()->active()
                ->get(['platform'])
                ->countBy(fn ($account) => $account->platform->value),
            'chart' => $this->dailyActivity($recentTargets, $timezone),
            'upcoming' => $user->posts()->withCount('targets')
                ->where('status', PostStatus::Scheduled)
                ->orderBy('scheduled_at')
                ->limit(5)
                ->get(),
            'needsAttention' => $user->posts()
                ->whereIn('status', [PostStatus::Failed, PostStatus::PartiallyFailed])
                ->where('updated_at', '>=', now()->subDays(7))
                ->withCount(['targets as failed_targets_count' => fn ($query) => $query->where('status', TargetStatus::Failed)])
                ->latest('updated_at')
                ->limit(5)
                ->get(),
            'recentPosts' => $user->posts()
                ->withCount([
                    'targets',
                    'targets as published_targets_count' => fn ($query) => $query->where('status', TargetStatus::Published),
                ])
                ->latest()
                ->limit(6)
                ->get(),
            'activeLive' => $user->liveStreams()->where('status', LiveStream::STATUS_LIVE)->latest()->first(),
            'schedulerRunning' => $lastSchedulerRun !== null && now()->timestamp - $lastSchedulerRun <= 180,
        ]);
    }

    /**
     * Published and failed account deliveries per day, oldest first.
     *
     * @param  Collection<int, PostTarget>  $targets
     * @return list<array{date: Carbon, published: int, failed: int}>
     */
    private function dailyActivity($targets, string $timezone): array
    {
        $byDay = $targets->groupBy(fn (PostTarget $target) => ($target->published_at ?? $target->updated_at)
            ->timezone($timezone)
            ->toDateString());

        $days = [];

        for ($offset = self::CHART_DAYS - 1; $offset >= 0; $offset--) {
            $date = now($timezone)->startOfDay()->subDays($offset);
            $dayTargets = $byDay->get($date->toDateString(), collect());

            $days[] = [
                'date' => $date,
                'published' => $dayTargets->where('status', TargetStatus::Published)->count(),
                'failed' => $dayTargets->where('status', TargetStatus::Failed)->count(),
            ];
        }

        return $days;
    }
}
