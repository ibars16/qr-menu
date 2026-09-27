<?php

namespace App\Service\Upload;

use Symfony\Component\Process\Process;

/**
 * FFmpeg-backed processing for dish clips (Product::$videoClip), run by
 * MenuAdminController::uploadProductClip() on a file UploadValidator has
 * already accepted. Two jobs:
 *
 * - trim(): a clip longer than the cap (or heavier than the stored-size
 *   cap) is cut to its first N seconds and re-encoded — re-encoded rather
 *   than stream-copied because a copy can only cut on a keyframe, which
 *   would overshoot the cap. Scaled down to fit 1280px (never up): the
 *   clip only ever renders in a small card/detail box, so a raw 4K phone
 *   video would just be wasted bandwidth for every diner. Audio dropped —
 *   the clip always plays muted.
 * - extractPoster(): grabs a frame as the JPEG poster (Product::$image),
 *   server-side, so a clip and its poster are saved together or not at all.
 *
 * Both return false on any FFmpeg failure, never throw — the caller turns
 * that into an honest "upload failed" and leaves the product untouched.
 */
final class ClipTranscoder
{
    private const TIMEOUT_SECONDS = 120;

    public function trim(string $sourcePath, string $targetPath, int $maxSeconds): bool
    {
        return $this->run([
            'ffmpeg', '-nostdin', '-y', '-loglevel', 'error',
            '-i', $sourcePath,
            '-t', (string) $maxSeconds,
            '-an',
            '-vf', "scale='min(1280,iw)':'min(1280,ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2",
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $targetPath,
        ], $targetPath);
    }

    public function extractPoster(string $clipPath, string $targetPath): bool
    {
        return $this->run([
            'ffmpeg', '-nostdin', '-y', '-loglevel', 'error',
            '-ss', '0.1',
            '-i', $clipPath,
            '-frames:v', '1',
            '-q:v', '3',
            '-f', 'image2',
            $targetPath,
        ], $targetPath);
    }

    /**
     * The clip's display size as the browser will show it — width/height
     * swapped for a ±90° rotation tag (phone videos are often stored
     * landscape with a rotation flag). Null if ffprobe can't read it.
     *
     * @return array{0: int, 1: int}|null
     */
    public function probeDimensions(string $clipPath): ?array
    {
        $process = new Process([
            'ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:stream_side_data=rotation:stream_tags=rotate',
            '-of', 'json', $clipPath,
        ]);
        $process->setTimeout(30);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        $stream = json_decode($process->getOutput(), true)['streams'][0] ?? null;
        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $rotation = (int) ($stream['tags']['rotate'] ?? 0);
        foreach ($stream['side_data_list'] ?? [] as $sideData) {
            if (isset($sideData['rotation'])) {
                $rotation = (int) $sideData['rotation'];
            }
        }

        return abs($rotation) % 180 === 90 ? [$height, $width] : [$width, $height];
    }

    /** @param string[] $command */
    private function run(array $command, string $targetPath): bool
    {
        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful() || !is_file($targetPath) || filesize($targetPath) === 0) {
            if (is_file($targetPath)) {
                unlink($targetPath);
            }

            return false;
        }

        return true;
    }
}
