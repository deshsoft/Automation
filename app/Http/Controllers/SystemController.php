<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SystemDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Server maintenance from the browser, for hosting without terminal access.
 * Protected by the SYSTEM_TOKEN from .env (works even before anyone can log in).
 */
class SystemController extends Controller
{
    private const SESSION_KEY = 'system_unlocked_until';

    private const MINIMUM_TOKEN_LENGTH = 32;

    /**
     * The only commands this page can run.
     *
     * @var array<string, array{label: string, command: string, parameters: array<string, mixed>}>
     */
    public const ACTIONS = [
        'clear-cache' => ['label' => 'Clear cache', 'command' => 'optimize:clear', 'parameters' => []],
        'migrate' => ['label' => 'Update database', 'command' => 'migrate', 'parameters' => ['--force' => true]],
        'storage-link' => ['label' => 'Create storage link', 'command' => 'storage:link', 'parameters' => []],
        'run-queue' => ['label' => 'Publish waiting posts now', 'command' => 'queue:work', 'parameters' => ['--stop-when-empty' => true, '--max-time' => 25]],
        'publish-due' => ['label' => 'Start due scheduled posts', 'command' => 'posts:publish-due', 'parameters' => []],
    ];

    public function show(Request $request, SystemDiagnostics $diagnostics): View
    {
        if (! $this->isEnabled()) {
            return view('system.disabled');
        }

        if (! $this->isUnlocked($request)) {
            return view('system.unlock');
        }

        return view('system.index', [
            'serverChecks' => $diagnostics->serverChecks(),
            'networkChecks' => $request->boolean('network') ? $diagnostics->networkChecks() : null,
            'queue' => $diagnostics->queueCounts(),
            'forcesIpv4' => SystemDiagnostics::forcesIpv4(),
            'ipv4FromEnv' => (bool) config('services.http.force_ipv4'),
            'cronCommand' => 'cd '.base_path().' && '.$this->cliPhp().' artisan schedule:run >> /dev/null 2>&1',
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        abort_unless($this->isEnabled(), 404);

        $request->validate(['token' => ['required', 'string']]);

        if (! hash_equals((string) config('app.system_token'), (string) $request->input('token'))) {
            Log::warning('Wrong system token entered', ['ip' => $request->ip()]);

            return back()->withErrors(['token' => 'Wrong access token.']);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, now()->addMinutes(30)->timestamp);

        return redirect()->route('system.show');
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('system.show');
    }

    public function run(Request $request): RedirectResponse
    {
        $this->ensureUnlocked($request);

        $validated = $request->validate(['action' => ['required', Rule::in(array_keys(self::ACTIONS))]]);
        $action = self::ACTIONS[$validated['action']];

        try {
            $exitCode = Artisan::call($action['command'], $action['parameters']);
            $output = trim(Artisan::output()) ?: 'Done.';
        } catch (Throwable $exception) {
            $exitCode = 1;
            $output = $exception->getMessage();
        }

        Log::info('System action run', ['action' => $validated['action'], 'exit_code' => $exitCode]);

        return redirect()->route('system.show')
            ->with($exitCode === 0 ? 'success' : 'error', $action['label'].($exitCode === 0 ? ': finished.' : ': failed.'))
            ->with('command_output', mb_substr($output, 0, 5000));
    }

    public function toggleIpv4(Request $request): RedirectResponse
    {
        $this->ensureUnlocked($request);

        $enable = $request->boolean('enabled');
        SystemDiagnostics::setForceIpv4($enable);

        return redirect()->route('system.show', ['network' => 1])->with('success', $enable
            ? 'IPv4 is now forced for Facebook, Google and TikTok calls.'
            : 'IPv4 is no longer forced.');
    }

    public function createUser(Request $request): RedirectResponse
    {
        $this->ensureUnlocked($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        User::create($validated);

        return redirect()->route('system.show')->with('success', "Login created for {$validated['email']}.");
    }

    /**
     * The command-line PHP for the cron job. Web requests run under php-fpm/lsphp,
     * so map cPanel's EasyApache path to its CLI binary.
     */
    private function cliPhp(): string
    {
        if (preg_match('#^(/opt/cpanel/ea-php\d+)/#', PHP_BINARY, $matches) === 1) {
            return $matches[1].'/root/usr/bin/php';
        }

        return str_ends_with(PHP_BINARY, '/php') ? PHP_BINARY : '/usr/local/bin/php';
    }

    private function isEnabled(): bool
    {
        return mb_strlen((string) config('app.system_token')) >= self::MINIMUM_TOKEN_LENGTH;
    }

    private function isUnlocked(Request $request): bool
    {
        return $this->isEnabled() && (int) $request->session()->get(self::SESSION_KEY, 0) > now()->timestamp;
    }

    private function ensureUnlocked(Request $request): void
    {
        abort_unless($this->isUnlocked($request), 403, 'Unlock the System page with the access token first.');
    }
}
