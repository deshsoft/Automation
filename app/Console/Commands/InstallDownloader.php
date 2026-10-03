<?php

namespace App\Console\Commands;

use App\Services\Downloading\VideoDownloader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use ZipArchive;

/**
 * Puts free standalone programs in storage/app/bin, so the downloader works
 * on cPanel where nothing can be installed system-wide.
 */
#[Signature('downloads:install {--ffmpeg : Also install ffmpeg (needed for HD and MP3, Linux only)} {--deno : Also install Deno (YouTube needs it for all formats)}')]
#[Description('Download the latest yt-dlp (and optionally ffmpeg and Deno) into storage/app/bin')]
class InstallDownloader extends Command
{
    public function handle(): int
    {
        $directory = VideoDownloader::binDirectory();
        $linux = PHP_OS_FAMILY === 'Linux';
        $arm = in_array(php_uname('m'), ['aarch64', 'arm64'], true);

        $ytDlp = match (true) {
            PHP_OS_FAMILY === 'Darwin' => 'yt-dlp_macos',
            $linux && $arm => 'yt-dlp_linux_aarch64',
            $linux => 'yt-dlp_linux',
            default => null,
        };

        if ($ytDlp === null) {
            $this->error('Only Linux and macOS are supported. Install yt-dlp yourself and set YTDLP_PATH.');

            return self::FAILURE;
        }

        if (! $this->fetch("https://github.com/yt-dlp/yt-dlp/releases/latest/download/{$ytDlp}", $directory.'/yt-dlp')) {
            return self::FAILURE;
        }

        chmod($directory.'/yt-dlp', 0755);
        $this->info('yt-dlp '.trim(Process::run([$directory.'/yt-dlp', '--version'])->output()).' installed.');

        if ($this->option('ffmpeg') && ! $this->installFfmpeg($directory, $linux, $arm)) {
            return self::FAILURE;
        }

        if ($this->option('deno') && ! $this->installDeno($directory, $linux, $arm)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function installFfmpeg(string $directory, bool $linux, bool $arm): bool
    {
        if (! $linux) {
            $this->warn('Automatic ffmpeg install is for Linux servers. On a Mac run: brew install ffmpeg');

            return true;
        }

        $archive = $directory.'/ffmpeg.tar.xz';
        $url = 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-'.($arm ? 'arm64' : 'amd64').'-static.tar.xz';

        if (! $this->fetch($url, $archive)) {
            return false;
        }

        $result = Process::path($directory)->timeout(300)->run(['tar', '-xJf', $archive, '--wildcards', '*/ffmpeg', '--strip-components=1']);
        File::delete($archive);

        if ($result->failed() || ! is_file($directory.'/ffmpeg')) {
            $this->error('Could not unpack ffmpeg: '.$result->errorOutput());

            return false;
        }

        chmod($directory.'/ffmpeg', 0755);
        $this->info('ffmpeg installed.');

        return true;
    }

    private function installDeno(string $directory, bool $linux, bool $arm): bool
    {
        $target = match (true) {
            $linux => ($arm ? 'aarch64' : 'x86_64').'-unknown-linux-gnu',
            default => ($arm ? 'aarch64' : 'x86_64').'-apple-darwin',
        };

        $archive = $directory.'/deno.zip';

        if (! $this->fetch("https://github.com/denoland/deno/releases/latest/download/deno-{$target}.zip", $archive)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true || ! $zip->extractTo($directory, 'deno')) {
            File::delete($archive);
            $this->error('Could not unpack Deno.');

            return false;
        }

        $zip->close();
        File::delete($archive);
        chmod($directory.'/deno', 0755);
        $this->info('Deno installed.');

        return true;
    }

    private function fetch(string $url, string $destination): bool
    {
        $this->line("Downloading {$url} ...");

        $response = Http::timeout(600)->withOptions(['sink' => $destination.'.tmp'])->get($url);

        if ($response->failed()) {
            File::delete($destination.'.tmp');
            $this->error("Download failed (HTTP {$response->status()}).");

            return false;
        }

        File::move($destination.'.tmp', $destination);

        return true;
    }
}
