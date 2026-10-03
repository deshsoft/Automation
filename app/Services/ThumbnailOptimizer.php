<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns any uploaded cover image into a JPG of at most 2 MB (YouTube's
 * thumbnail limit), so large photos straight from a phone or camera work.
 */
class ThumbnailOptimizer
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Longest side of the stored image; YouTube shows thumbnails at 1280×720.
     */
    public const MAX_SIDE = 1920;

    /**
     * Save the image on the public disk and return its path.
     */
    public function store(UploadedFile $file): string
    {
        $image = extension_loaded('gd') ? @imagecreatefromstring((string) file_get_contents($file->getRealPath())) : false;

        if (! $image instanceof GdImage) {
            return $file->store('thumbnails', 'public');
        }

        $image = $this->flattened($this->resized($image, self::MAX_SIDE));
        $jpeg = $this->smallEnoughJpeg($image);

        $path = 'thumbnails/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return $path;
    }

    /**
     * Lower the quality, then the size, until the JPG fits in 2 MB.
     */
    private function smallEnoughJpeg(GdImage $image): string
    {
        foreach ([self::MAX_SIDE, 1280, 960] as $side) {
            $image = $this->resized($image, $side);

            foreach ([90, 80, 70, 60] as $quality) {
                $jpeg = $this->encode($image, $quality);

                if (strlen($jpeg) <= self::MAX_BYTES) {
                    return $jpeg;
                }
            }
        }

        return $this->encode($image, 50);
    }

    private function resized(GdImage $image, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $maxSide / max($width, $height);

        if ($scale >= 1) {
            return $image;
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /**
     * JPG has no transparency, so transparent PNG areas become white.
     */
    private function flattened(GdImage $image): GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    private function encode(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
