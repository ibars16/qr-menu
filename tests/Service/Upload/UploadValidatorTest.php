<?php

namespace App\Tests\Service\Upload;

use App\Service\Upload\ContentModerationInterface;
use App\Service\Upload\ContentModerationResult;
use App\Service\Upload\NullContentModeration;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadValidationError;
use App\Service\Upload\UploadValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pure-logic coverage for UploadValidator — no DB/kernel needed, only real
 * temp files on disk (UploadedFile needs a real, readable path to sniff a
 * MIME type from). Written after an explicit image was uploaded as a
 * restaurant logo with zero content/type validation in place; these tests
 * pin down that the real-content MIME check, size/dimension limits, and the
 * non-predictable filename all actually work, not just that the code compiles.
 */
final class UploadValidatorTest extends TestCase
{
    /** @var string[] temp files to clean up */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function validator(?ContentModerationInterface $moderation = null): UploadValidator
    {
        return new UploadValidator($moderation ?? new NullContentModeration());
    }

    private function pngUpload(int $width, int $height, string $originalName = 'photo.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 60, 200));

        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        imagepng($image, $path);
        imagedestroy($image);

        $this->tempFiles[] = $path;

        return new UploadedFile($path, $originalName, 'image/png', null, true);
    }

    private function textUploadDisguisedAsPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($path, str_repeat('this is not an image', 50));

        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'totally-a-logo.png', 'image/png', null, true);
    }

    /**
     * A minimal, well-formed MP4 (ftyp + moov/mvhd version 0 + mdat) with an
     * exact duration via a 1000-unit timescale — real bytes finfo genuinely
     * sniffs as video/mp4, and readMp4DurationSeconds() genuinely parses,
     * with no ffmpeg/GD anywhere (matches this feature's "camino A" decision).
     */
    private function mp4Bytes(float $durationSeconds): string
    {
        $ftypData = 'isom' . pack('N', 0x200) . 'isomiso2avc1mp41';
        $ftyp = pack('N', 8 + \strlen($ftypData)) . 'ftyp' . $ftypData;

        $timescale = 1000;
        $duration = (int) round($durationSeconds * $timescale);
        $mvhdBody = \chr(0) . "\x00\x00\x00"
            . pack('N', 0)              // creation_time
            . pack('N', 0)              // modification_time
            . pack('N', $timescale)
            . pack('N', $duration)
            . pack('N', 0x00010000)     // rate
            . pack('n', 0x0100) . "\x00\x00" // volume + reserved
            . str_repeat("\x00", 8)     // reserved
            . str_repeat("\x00", 36)    // matrix (shortened — irrelevant to duration parsing)
            . str_repeat("\x00", 24)    // pre_defined
            . pack('N', 2);             // next_track_id
        $mvhd = pack('N', 8 + \strlen($mvhdBody)) . 'mvhd' . $mvhdBody;
        $moov = pack('N', 8 + \strlen($mvhd)) . 'moov' . $mvhd;

        $mdat = pack('N', 16) . 'mdat' . str_repeat("\x00", 8);

        return $ftyp . $moov . $mdat;
    }

    private function mp4Upload(float $durationSeconds, string $originalName = 'clip.mp4'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($path, $this->mp4Bytes($durationSeconds));

        $this->tempFiles[] = $path;

        return new UploadedFile($path, $originalName, 'video/mp4', null, true);
    }

    public function testAcceptsValidLogoAndAssignsNonPredictableSafeFilename(): void
    {
        $file = $this->pngUpload(300, 300, 'my-real-name.png');

        $result = $this->validator()->validate($file, UploadProfile::Logo);

        self::assertTrue($result->isValid);
        self::assertNull($result->error);
        self::assertSame('image/png', $result->mimeType);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $result->safeFilename);
        self::assertStringNotContainsString('my-real-name', $result->safeFilename);
    }

    public function testSafeFilenameIsNotPredictableAcrossCalls(): void
    {
        $file = $this->pngUpload(300, 300);

        $first = $this->validator()->validate($file, UploadProfile::Logo);
        $second = $this->validator()->validate($file, UploadProfile::Logo);

        // 32 hex chars (128 bits of random_bytes) rather than a uniqid()-style
        // timestamp-based name — same-second calls must not collide or share
        // a predictable prefix.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $first->safeFilename);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $second->safeFilename);
        self::assertNotSame($first->safeFilename, $second->safeFilename);
    }

    public function testRejectsNonImageFileDisguisedWithImageExtension(): void
    {
        $file = $this->textUploadDisguisedAsPng();

        $result = $this->validator()->validate($file, UploadProfile::Logo);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::UnsupportedType, $result->error);
        self::assertNull($result->safeFilename);
    }

    public function testRejectsFileOverProfileSizeLimit(): void
    {
        $file = $this->pngUpload(150, 150);
        // Pad past the logo profile's 2MB cap with trailing bytes. PNG
        // decoders/finfo only look at the header, so this stays detected as
        // image/png while filesystem size exceeds the limit.
        file_put_contents($file->getPathname(), str_repeat("\0", 3 * 1024 * 1024), FILE_APPEND);

        $result = $this->validator()->validate($file, UploadProfile::Logo);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::TooLarge, $result->error);
    }

    public function testRejectsDimensionsBelowProfileMinimum(): void
    {
        $file = $this->pngUpload(50, 50);

        $result = $this->validator()->validate($file, UploadProfile::DishImage);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::DimensionsTooSmall, $result->error);
    }

    public function testRejectsDimensionsAboveProfileMaximum(): void
    {
        $file = $this->pngUpload(2500, 2500);

        $result = $this->validator()->validate($file, UploadProfile::Logo);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::DimensionsTooLarge, $result->error);
    }

    public function testMenuImportProfileSkipsDimensionChecks(): void
    {
        // 10x10 would fail every other profile's minimum, but menu-import
        // pages have no dimension floor (legitimate menu photos vary wildly).
        $file = $this->pngUpload(10, 10);

        $result = $this->validator()->validate($file, UploadProfile::MenuImportPage);

        self::assertTrue($result->isValid);
    }

    public function testTreatsIniSizeErrorAsTooLargeNotGenericInvalid(): void
    {
        // PHP itself truncates a file exceeding upload_max_filesize before
        // it ever reaches our own maxSizeBytes check — the resulting
        // UploadedFile is invalid, but the reason is "too big", not "no
        // valid file was picked", so it must map to the same honest
        // TooLarge error as our own size check, not the generic one.
        $file = new UploadedFile('/tmp/does-not-need-to-exist', 'photo.png', 'image/png', \UPLOAD_ERR_INI_SIZE, true);

        $result = $this->validator()->validate($file, UploadProfile::DishImage);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::TooLarge, $result->error);
    }

    public function testTreatsFormSizeErrorAsTooLargeNotGenericInvalid(): void
    {
        $file = new UploadedFile('/tmp/does-not-need-to-exist', 'photo.png', 'image/png', \UPLOAD_ERR_FORM_SIZE, true);

        $result = $this->validator()->validate($file, UploadProfile::DishImage);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::TooLarge, $result->error);
    }

    public function testOtherUploadErrorsStayGenericInvalidFile(): void
    {
        // A size-unrelated upload failure (e.g. the client aborted mid
        // transfer) must NOT be reinterpreted as "too large" — only the two
        // size-specific PHP error codes get that treatment.
        $file = new UploadedFile('/tmp/does-not-need-to-exist', 'photo.png', 'image/png', \UPLOAD_ERR_PARTIAL, true);

        $result = $this->validator()->validate($file, UploadProfile::DishImage);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::InvalidFile, $result->error);
    }

    public function testAcceptsValidMp4ClipUnderTheDurationCap(): void
    {
        $file = $this->mp4Upload(3.0);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertTrue($result->isValid);
        self::assertNull($result->error);
        self::assertSame('video/mp4', $result->mimeType);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.mp4$/', $result->safeFilename);
    }

    public function testAcceptsAClipRightAtTheDurationCap(): void
    {
        $file = $this->mp4Upload(10.0);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertTrue($result->isValid);
    }

    public function testRejectsAClipOverTheDurationCap(): void
    {
        $file = $this->mp4Upload(10.1);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::DurationTooLong, $result->error);
    }

    public function testRejectsAClipWayOverTheDurationCap(): void
    {
        // Guards against someone uploading a whole movie under a .mp4 name —
        // the entire reason this profile parses duration at all.
        $file = $this->mp4Upload(90 * 60);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::DurationTooLong, $result->error);
    }

    public function testRejectsFileOverTheClipProfileSizeLimit(): void
    {
        $file = $this->mp4Upload(3.0);
        // Pad past dish_clip's 8MB cap with trailing bytes after mdat — finfo
        // only looks at the header/early boxes, so this stays detected as
        // video/mp4 while filesystem size exceeds the limit.
        file_put_contents($file->getPathname(), str_repeat("\0", 9 * 1024 * 1024), FILE_APPEND);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::TooLarge, $result->error);
    }

    public function testRejectsNonMp4FileForTheClipProfile(): void
    {
        // Plain text, not a video at all (reuses the same fixture the image
        // tests use to prove real-content sniffing, no GD needed) — must be
        // UnsupportedType, not mistaken for a malformed MP4.
        $file = $this->textUploadDisguisedAsPng();

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::UnsupportedType, $result->error);
    }

    public function testRejectsAWebmFileForTheClipProfile(): void
    {
        // webm is deliberately out of scope (see ALLOWED_VIDEO_EXTENSIONS_BY_MIME's
        // own comment) — a real webm must be rejected as unsupported, not
        // silently accepted because "it's a video". Forces the sniffed MIME
        // directly (a bare EBML signature isn't enough bytes for finfo to
        // positively identify webm vs. generic Matroska/octet-stream — either
        // way it's not video/mp4, which is the actual thing under test here).
        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($path, "\x1A\x45\xDF\xA3" . str_repeat("\x00", 60));
        $this->tempFiles[] = $path;
        $file = new UploadedFile($path, 'clip.webm', 'video/webm', null, true);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::UnsupportedType, $result->error);
    }

    public function testRejectsAClipWhoseDurationCannotBeParsed(): void
    {
        // Real MP4 signature (finfo sniffs video/mp4 off the ftyp box alone)
        // but no moov/mvhd box at all — must fail closed, not be treated as
        // "no duration limit".
        $ftypData = 'isom' . pack('N', 0x200) . 'isomiso2avc1mp41';
        $ftyp = pack('N', 8 + \strlen($ftypData)) . 'ftyp' . $ftypData;
        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($path, $ftyp . pack('N', 16) . 'mdat' . str_repeat("\x00", 8));
        $this->tempFiles[] = $path;
        $file = new UploadedFile($path, 'no-moov.mp4', 'video/mp4', null, true);

        $result = $this->validator()->validate($file, UploadProfile::DishClip);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::UnsupportedType, $result->error);
    }

    public function testDishImageProfileStillRejectsAnMp4(): void
    {
        // The photo tab's own upload must not accept a video just because
        // the clip profile now exists — profiles are isolated allow-lists,
        // not a union of everything the validator knows about.
        $file = $this->mp4Upload(3.0);

        $result = $this->validator()->validate($file, UploadProfile::DishImage);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::UnsupportedType, $result->error);
    }

    public function testModerationHookCanRejectAnOtherwiseValidUpload(): void
    {
        $rejectingModeration = new class implements ContentModerationInterface {
            public function moderate(string $filePath, string $mimeType): ContentModerationResult
            {
                return ContentModerationResult::rejected();
            }
        };

        $file = $this->pngUpload(300, 300);

        $result = $this->validator($rejectingModeration)->validate($file, UploadProfile::Logo);

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::Rejected, $result->error);
    }
}
