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
