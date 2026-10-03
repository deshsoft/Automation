<?php

namespace App\Services\Downloading;

use App\Exceptions\DownloadException;
use App\Models\VideoDownload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads YouTube and Facebook videos with the free yt-dlp program.
 *
 * HD videos come as separate video and audio streams, so ffmpeg is needed to
 * join them. Without ffmpeg only single-file formats are used (often 360p
 * on YouTube).
 */
class VideoDownloader
{
    /**
     * Seconds yt-dlp may run; below the job and queue worker timeouts.
     */
    public const TIMEOUT = 840;

    /**
     * Download the video and return the details of the saved file.
     *
     * @return array{title: ?string, thumbnail_url: ?string, duration: ?int, file_path: string, file_size: int}
     *
     * @throws DownloadException
     */
    public function download(VideoDownload $download): array
    {
        $directory = 'downloads/'.$download->user_id;
        Storage::disk('local')->makeDirectory($directory);

        $result = Process::timeout(self::TIMEOUT)->run($this->command($download, $directory));

        if ($result->exitCode() === 127) {
            throw new DownloadException('yt-dlp is not installed on this server. Run "php artisan downloads:install" (see README).');
        }

        if ($result->failed()) {
            throw new DownloadException($this->errorMessage($result->errorOutput() ?: $result->output()));
        }

        $info = $this->parseInfo($result->output());
        $absolutePath = $info['filepath'] ?? null;

        if (! is_string($absolutePath) || ! is_file($absolutePath)) {
            throw new DownloadException('Nothing was downloaded. The video may be larger than '.config('services.downloader.max_filesize_mb').' MB, or it is a live stream that has not ended.');
        }

        return [
            'title' => isset($info['title']) ? Str::limit((string) $info['title'], 250, '') : null,
            'thumbnail_url' => isset($info['thumbnail']) && strlen((string) $info['thumbnail']) <= 2048 ? (string) $info['thumbnail'] : null,
            'duration' => isset($info['duration']) ? (int) round((float) $info['duration']) : null,
            'file_path' => $directory.'/'.basename($absolutePath),
            'file_size' => (int) filesize($absolutePath),
        ];
    }

    /**
     * @return list<string>
     */
    public function command(VideoDownload $download, string $directory): array
    {
        $ffmpeg = $this->ffmpeg();

        $command = [
            $this->binary(),
            '--no-playlist',
            '--no-progress',
            '--no-mtime',
            '--restrict-filenames',
            '--socket-timeout', '30',
            '--retries', '3',
            '--max-filesize', config('services.downloader.max_filesize_mb').'M',
            '-o', Storage::disk('local')->path($directory).'/'.$download->id.'.%(ext)s',
            '--print', 'after_move:%(.{title,duration,thumbnail,filepath})j',
            '--no-simulate',
        ];

        if ($ffmpeg !== null) {
            array_push($command, '--ffmpeg-location', $ffmpeg);
        }

        if ($deno = $this->deno()) {
            array_push($command, '--js-runtimes', 'deno:'.$deno);
        }

        if ($cookies = config('services.downloader.cookies')) {
            array_push($command, '--cookies', $cookies);
        }

        array_push($command, ...$this->formatOptions($download->quality, $ffmpeg !== null));

        array_push($command, '--', $download->url);

        return $command;
    }

    /**
     * Format selection for the chosen quality. Prefers H.264 + AAC so the file
     * plays everywhere and can be uploaded to Facebook or YouTube again.
     *
     * @return list<string>
     */
    private function formatOptions(string $quality, bool $hasFfmpeg): array
    {
        if ($quality === 'audio') {
            return $hasFfmpeg
                ? ['-f', 'ba/b', '-x', '--audio-format', 'mp3', '--audio-quality', '0']
                : ['-f', 'ba[ext=m4a]/ba/b'];
        }

        $sort = $quality === 'best' ? 'res,vcodec:h264,acodec:aac' : "res:{$quality},vcodec:h264,acodec:aac";

        return $hasFfmpeg
            ? ['-f', 'bv*+ba/b', '-S', $sort, '--merge-output-format', 'mp4']
            : ['-f', 'b', '-S', $sort];
    }

    /**
     * yt-dlp prints the info JSON on the last line of its output.
     *
     * @return array<string, mixed>
     */
    private function parseInfo(string $output): array
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);

        foreach ($lines as $line) {
            $info = json_decode($line, true);

            if (is_array($info)) {
                return $info;
            }
        }

        return [];
    }

    private function errorMessage(string $output): string
    {
        preg_match_all('/^ERROR:\s*(.+)$/m', $output, $matches);
        $message = trim(end($matches[1]) ?: Str::afterLast(trim($output), "\n")) ?: 'yt-dlp failed without an error message.';

        if (Str::contains($message, ['not a bot', 'Sign in to confirm', 'login required', 'cookies'], ignoreCase: true)) {
            $message .= ' — The site asked for a login. Set YTDLP_COOKIES to a cookies.txt file exported from your browser (see README).';
        }

        return mb_substr($message, 0, 2000);
    }

    /**
     * yt-dlp program: YTDLP_PATH, then storage/app/bin/yt-dlp, then the system PATH.
     */
    public function binary(): string
    {
        return config('services.downloader.binary')
            ?: $this->findExecutable('yt-dlp')
            ?? 'yt-dlp';
    }

    public function ffmpeg(): ?string
    {
        return config('services.downloader.ffmpeg') ?: $this->findExecutable('ffmpeg');
    }

    private function deno(): ?string
    {
        return config('services.downloader.deno') ?: $this->findExecutable('deno');
    }

    private function findExecutable(string $name): ?string
    {
        $local = storage_path('app/bin/'.$name);

        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', ...explode(PATH_SEPARATOR, (string) getenv('PATH'))] as $directory) {
            if ($directory !== '' && is_file($directory.'/'.$name) && is_executable($directory.'/'.$name)) {
                return $directory.'/'.$name;
            }
        }

        return null;
    }

    public function isInstalled(): bool
    {
        $binary = $this->binary();

        return $binary !== 'yt-dlp' && is_file($binary) && is_executable($binary);
    }

    /**
     * Folder for the programs installed by "php artisan downloads:install".
     */
    public static function binDirectory(): string
    {
        File::ensureDirectoryExists(storage_path('app/bin'));

        return storage_path('app/bin');
    }
}
