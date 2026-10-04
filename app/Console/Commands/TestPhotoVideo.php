<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\Downloading\PhotoFallback;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('downloads:test-photo-video {link? : Optionally also fetch the photo of this Facebook post}')]
#[Description('Check that this server can turn a photo into the short video used for YouTube')]
class TestPhotoVideo extends Command
{
    public function handle(PhotoFallback $fallback): int
    {
        $post = new Post;
        $post->id = 0;

        if ($link = $this->argument('link')) {
            $photoPath = $fallback->importPhoto($link, $post);

            if ($photoPath === null) {
                $this->error('Getting the photo failed: '.$fallback->lastError);

                return self::FAILURE;
            }

            $this->info('Photo downloaded from the link.');
        } else {
            $photoPath = 'media/test-photo-video.jpg';
            $image = imagecreatetruecolor(1200, 1500);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 79, 70, 229));
            ob_start();
            imagejpeg($image);
            Storage::disk('public')->put($photoPath, (string) ob_get_clean());
        }

        $started = microtime(true);
        $videoPath = $fallback->makeVideo($photoPath, $post);
        Storage::disk('public')->delete($photoPath);

        if ($videoPath === null) {
            $this->error('Making the video failed: '.$fallback->lastError);

            return self::FAILURE;
        }

        $size = Storage::disk('public')->size($videoPath);
        Storage::disk('public')->delete($videoPath);

        $this->info(sprintf('OK: a %d-second video (%.1f MB) was made in %.1f seconds. YouTube photo posts will work.', PhotoFallback::VIDEO_SECONDS, $size / 1048576, microtime(true) - $started));

        return self::SUCCESS;
    }
}
