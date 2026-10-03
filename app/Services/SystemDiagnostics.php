<?php

namespace App\Services;

use App\Services\Downloading\VideoDownloader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Health checks for the server, so problems can be found without a terminal.
 */
class SystemDiagnostics
{
    public function __construct(private VideoDownloader $downloader) {}

    /**
     * The APIs this app must be able to reach.
     *
     * @var array<string, string>
     */
    public const API_HOSTS = [
        'Facebook / Instagram' => 'https://graph.facebook.com',
        'Facebook video upload' => 'https://graph-video.facebook.com',
        'YouTube' => 'https://www.googleapis.com',
        'Google login' => 'https://oauth2.googleapis.com',
        'TikTok' => 'https://open.tiktokapis.com',
    ];

    /**
     * Whether outgoing API calls are forced to IPv4 (set from the System page or HTTP_FORCE_IPV4).
     */
    public static function forcesIpv4(): bool
    {
        return (bool) config('services.http.force_ipv4') || File::exists(self::ipv4FlagPath());
    }

    public static function setForceIpv4(bool $enabled): void
    {
        if ($enabled) {
            File::ensureDirectoryExists(dirname(self::ipv4FlagPath()));
            File::put(self::ipv4FlagPath(), 'enabled');
        } else {
            File::delete(self::ipv4FlagPath());
        }
    }

    public static function ipv4FlagPath(): string
    {
        return storage_path('app/system/force-ipv4');
    }

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function serverChecks(): array
    {
        $lastSchedulerRun = Cache::get('scheduler:last-run');

        return [
            $this->check('PHP version', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION.' (8.3 or newer needed)'),
            ...array_map(
                fn (string $extension) => $this->check("PHP extension: {$extension}", extension_loaded($extension), extension_loaded($extension) ? 'installed' : 'missing, enable it in cPanel → Select PHP Version'),
                ['curl', 'mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'dom'],
            ),
            $this->check('Database connection', $this->databaseWorks(), $this->databaseWorks() ? 'connected' : 'cannot connect, check DB_* in .env'),
            $this->check('Database tables up to date', $this->pendingMigrations() === 0, $this->pendingMigrations() === 0 ? 'all migrations ran' : $this->pendingMigrations().' pending, click "Update database"'),
            $this->check('Storage folder writable', is_writable(storage_path()), is_writable(storage_path()) ? 'writable' : 'not writable, set storage/ permissions to 755'),
            $this->check('Public storage link', File::exists(public_path('storage')), File::exists(public_path('storage')) ? 'exists' : 'missing, click "Create storage link" (photos and videos need it)'),
            $this->check('Cron job (scheduler)', $lastSchedulerRun !== null && now()->timestamp - $lastSchedulerRun <= 180, $lastSchedulerRun ? 'last run '.now()->createFromTimestamp($lastSchedulerRun)->diffForHumans() : 'never ran, add the cron job in cPanel'),
            $this->check('APP_URL', rtrim((string) config('app.url'), '/') === request()->getSchemeAndHttpHost(), 'is '.config('app.url').', this site is '.request()->getSchemeAndHttpHost()),
            $this->check('Can run programs (proc_open)', $this->canRunPrograms(), $this->canRunPrograms() ? 'enabled' : 'disabled by the host: video downloads cannot work, ask hosting to enable proc_open'),
            $this->check('Video downloader (yt-dlp)', $this->downloader->isInstalled(), $this->downloader->isInstalled() ? 'installed' : 'not installed, click "Install video downloader (1/3)"'),
            $this->check('ffmpeg (HD videos)', $this->downloader->ffmpeg() !== null, $this->downloader->ffmpeg() !== null ? 'installed' : 'not installed, click "Install video downloader (2/3)"; without it downloads are often 360p'),
            $this->check('Deno (YouTube)', $this->downloader->deno() !== null, $this->downloader->deno() !== null ? 'installed' : 'not installed, click "Install video downloader (3/3)"; YouTube may refuse downloads without it'),
            $this->check('Debug mode off', ! config('app.debug'), config('app.debug') ? 'APP_DEBUG=true, set it to false on a live server' : 'off'),
            $this->check('Upload limit', $this->uploadLimitMb() >= 100, 'upload_max_filesize '.ini_get('upload_max_filesize').', post_max_size '.ini_get('post_max_size').' (raise in cPanel → MultiPHP INI Editor for big videos)'),
        ];
    }

    /**
     * Try each API with the server's default network and with IPv4 only.
     *
     * @return list<array{label: string, url: string, default: array{ok: bool, detail: string}, ipv4: array{ok: bool, detail: string}}>
     */
    public function networkChecks(): array
    {
        $results = [];

        foreach (self::API_HOSTS as $label => $url) {
            $results[] = [
                'label' => $label,
                'url' => $url,
                'default' => $this->reach($url, []),
                'ipv4' => $this->reach($url, ['force_ip_resolve' => 'v4']),
            ];
        }

        return $results;
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function queueCounts(): array
    {
        try {
            return [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ];
        } catch (Throwable) {
            return ['pending' => 0, 'failed' => 0];
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, detail: string}
     */
    private function reach(string $url, array $options): array
    {
        $started = microtime(true);

        try {
            // Any HTTP answer (even 400/404) proves the network path works.
            $status = Http::withOptions($options)->connectTimeout(8)->timeout(10)->get($url)->status();

            return ['ok' => true, 'detail' => 'HTTP '.$status.' in '.(int) round((microtime(true) - $started) * 1000).' ms'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => mb_substr($exception->getMessage(), 0, 160)];
        }
    }

    private function canRunPrograms(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }

    private function databaseWorks(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function pendingMigrations(): int
    {
        try {
            Artisan::call('migrate:status', ['--pending' => true]);

            return substr_count(Artisan::output(), 'Pending');
        } catch (Throwable) {
            return -1;
        }
    }

    private function uploadLimitMb(): int
    {
        $toMb = function (string $value): int {
            $number = (int) $value;

            return match (strtolower(substr(trim($value), -1))) {
                'g' => $number * 1024,
                'k' => (int) ($number / 1024),
                'm' => $number,
                default => (int) ($number / 1024 / 1024),
            };
        };

        return min($toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size')));
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function check(string $label, bool $ok, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }
}
