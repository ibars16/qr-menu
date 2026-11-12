<?php

namespace App\Service\DishPhoto;

use Symfony\Component\Process\Process;

/**
 * A generated photo (often a 1–2 MB PNG) → a light JPEG: fit within 1200px
 * (never upscaled), JPEG quality about 82 (FFmpeg's mjpeg -q:v 4). FFmpeg
 * because the container has neither GD nor Imagick, and production already
 * needs FFmpeg for dish clips (ClipTranscoder).
 *
 * Also shrinks an owner's photo before "Mejorar con IA" sends it
 * ($maxSide = DishPhotoGenerator::ENHANCE_INPUT_MAX_SIDE).
 *
 * Returns false on any FFmpeg failure (or FFmpeg missing), never throws:
 * DishPhotoStorage then keeps the provider's original file instead.
 */
class DishPhotoJpegEncoder
{
    private const MAX_SIDE = 1200;
    private const TIMEOUT_SECONDS = 30;

    public function encode(string $sourcePath, string $targetPath, int $maxSide = self::MAX_SIDE): bool
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-loglevel', 'error',
            '-i', $sourcePath,
            '-frames:v', '1',
            '-vf', sprintf("scale='min(%1\$d,iw)':'min(%1\$d,ih)':force_original_aspect_ratio=decrease", $maxSide),
            '-q:v', '4',
            '-pix_fmt', 'yuvj420p',
            '-f', 'image2', '-c:v', 'mjpeg',
            $targetPath,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable) {
            @unlink($targetPath);

            return false;
        }

        if (!$process->isSuccessful() || !is_file($targetPath) || filesize($targetPath) === 0) {
            @unlink($targetPath);

            return false;
        }

        return true;
    }
}
