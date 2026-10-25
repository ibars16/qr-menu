<?php

namespace App\Tests\Service\Upload;

use App\Service\Upload\ContentModerationInterface;
use App\Service\Upload\ContentModerationResult;
use App\Service\Upload\NullContentModeration;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadQualityWarning;
use App\Service\Upload\UploadValidationError;
use App\Service\Upload\UploadValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pure-logic coverage for the fatal/warning split added to UploadValidator:
 * quality issues (below-minimum dimensions, portrait aspect ratio) on the
 * hero_image profile are now non-fatal — they return needsConfirmation()
 * instead of failure(), and only proceed to success() once the caller
 * passes $qualityWarningsConfirmed = true. dish_image and logo don't opt
 * in (UploadProfile::HeroImage-only for now) and must keep rejecting small
 * dimensions outright, unchanged. Narrower than the recommended width
 * (but above the minimum) is a third, purely informative warning: the
 * upload goes straight through and the caller reports it after saving.
 *
 * Uses static fixture files (real bytes on disk, made with ImageMagick),
 * not GD (imagecreatetruecolor) — GD is unavailable in this environment,
 * and the whole point of this suite is that it actually runs here.
 *
 * The two "critical guard" tests at the bottom are the ones that matter
 * most: $qualityWarningsConfirmed must NEVER be able to turn a security
 * failure (bad MIME, moderation rejection) into a pass.
 */
final class UploadValidatorQualityWarningTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    private function validator(?ContentModerationInterface $moderation = null): UploadValidator
    {
        return new UploadValidator($moderation ?? new NullContentModeration());
    }

    private function fixture(string $filename, string $mimeType = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile(self::FIXTURES . '/' . $filename, $filename, $mimeType, null, true);
    }

    public function testCleanWideHeroImagePassesWithNoWarnings(): void
    {
        // 1600x800 — exactly the recommended width (the boundary passes), landscape.
        $result = $this->validator()->validate($this->fixture('upload_hero_wide.jpg'), UploadProfile::HeroImage);

        self::assertTrue($result->isValid);
        self::assertNull($result->error);
        self::assertSame([], $result->warnings);
        self::assertNotNull($result->safeFilename);
        self::assertSame(1600, $result->width);
        self::assertSame(800, $result->height);
    }

    public function testNarrowHeroImageIsAcceptedWithAnInformativeWidthWarning(): void
    {
        // 800x400 — above the 300px minimum, below the 1600px recommended width.
        $result = $this->validator()->validate($this->fixture('upload_hero_ok.jpg'), UploadProfile::HeroImage);

        self::assertTrue($result->isValid, 'an informative warning must not hold the upload back for confirmation');
        self::assertNull($result->error);
        self::assertSame([UploadQualityWarning::WidthBelowRecommended], $result->warnings);
        self::assertNotNull($result->safeFilename);
        self::assertSame(800, $result->width, 'the real size is reported, for the warning text');
        self::assertSame(400, $result->height);
    }

    public function testOnlyTheWidthWarningSkipsConfirmation(): void
    {
        self::assertFalse(UploadQualityWarning::WidthBelowRecommended->requiresConfirmation());
        self::assertTrue(UploadQualityWarning::DimensionsBelowRecommended->requiresConfirmation());
        self::assertTrue(UploadQualityWarning::PortraitAspectRatio->requiresConfirmation());
    }

    public function testRecommendedWidthIsHeroImageOnly(): void
    {
        self::assertSame(1600, UploadValidator::recommendedWidth(UploadProfile::HeroImage));
        self::assertNull(UploadValidator::recommendedWidth(UploadProfile::Logo));
        self::assertNull(UploadValidator::recommendedWidth(UploadProfile::DishImage));
    }

    public function testHeroImageHintLimitsComeFromTheProfile(): void
    {
        self::assertSame(300, UploadValidator::minDimension(UploadProfile::HeroImage));
        self::assertSame(5000, UploadValidator::maxDimension(UploadProfile::HeroImage));
        self::assertSame(8, UploadValidator::maxSizeMegabytes(UploadProfile::HeroImage));
    }

    public function testSmallHeroImageNeedsConfirmationInsteadOfFailing(): void
    {
        // 250x150 — below the 300px minimum on both axes, landscape (no portrait warning).
        $result = $this->validator()->validate($this->fixture('upload_hero_small.jpg'), UploadProfile::HeroImage);

        self::assertFalse($result->isValid);
        self::assertNull($result->error, 'a quality-only issue must not be reported as a fatal error');
        self::assertSame([UploadQualityWarning::DimensionsBelowRecommended], $result->warnings, 'no width warning on top: this one already reports the size');
        self::assertNull($result->safeFilename, 'nothing should be ready to persist until confirmed');
        self::assertSame(250, $result->width, 'the real size is reported, for the confirmation text');
        self::assertSame(150, $result->height);
    }

    public function testPortraitHeroImageNeedsConfirmationWithAspectWarning(): void
    {
        // 400x600 — clears the 300px minimum on both axes, but taller than
        // wide (and narrower than recommended, reported alongside).
        $result = $this->validator()->validate($this->fixture('upload_hero_portrait.jpg'), UploadProfile::HeroImage);

        self::assertFalse($result->isValid);
        self::assertNull($result->error);
        self::assertSame([UploadQualityWarning::PortraitAspectRatio, UploadQualityWarning::WidthBelowRecommended], $result->warnings);
    }

    public function testTinyPortraitHeroImageReturnsBothWarnings(): void
    {
        // 200x350 — below minimum AND portrait: both issues apply at once.
        $result = $this->validator()->validate($this->fixture('upload_hero_tiny_portrait.jpg'), UploadProfile::HeroImage);

        self::assertFalse($result->isValid);
        self::assertNull($result->error);
        self::assertSame(
            [UploadQualityWarning::DimensionsBelowRecommended, UploadQualityWarning::PortraitAspectRatio],
            $result->warnings
        );
    }

    public function testConfirmingQualityWarningsAllowsTheSameSmallImageThrough(): void
    {
        $result = $this->validator()->validate(
            $this->fixture('upload_hero_small.jpg'),
            UploadProfile::HeroImage,
            qualityWarningsConfirmed: true
        );

        self::assertTrue($result->isValid);
        self::assertNull($result->error);
        self::assertSame([UploadQualityWarning::DimensionsBelowRecommended], $result->warnings, 'warnings are still reported even once confirmed, for the caller to log/flash informationally');
        self::assertNotNull($result->safeFilename);
    }

    public function testDishImageProfileDoesNotOptIntoWarningsAndStillRejectsSmallDimensions(): void
    {
        // Same undersized fixture, but the dish_image profile — no
        // qualityWarningsEnabled there, so this must be a hard failure,
        // exactly as before this change, confirmation flag or not.
        $result = $this->validator()->validate(
            $this->fixture('upload_hero_small.jpg'),
            UploadProfile::DishImage,
            qualityWarningsConfirmed: true
        );

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::DimensionsTooSmall, $result->error);
        self::assertSame([], $result->warnings);
    }

    public function testLogoProfileDoesNotOptIntoWarningsAndStillRejectsSmallDimensions(): void
    {
        // Logo's own minimum is 100x100 (tighter than hero's 300, looser
        // than dish's 400) — upload_hero_small.jpg (250x150) actually
        // clears that bar, so this needs its own, genuinely-smaller fixture.
        $result = $this->validator()->validate(
            $this->fixture('upload_tiny_80.jpg'),
            UploadProfile::Logo,
            qualityWarningsConfirmed: true
        );

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::DimensionsTooSmall, $result->error);
    }

    // ── Critical guard: the confirmation flag must never bypass security ──

    public function testFakeMimeIsRejectedEvenWithQualityWarningsConfirmed(): void
    {
        // A plain text file with a .png name and a spoofed image/png
        // Content-Type — finfo sniffs the real bytes and must catch this
        // regardless of the confirmation flag, which only ever concerns
        // quality warnings, never MIME/type checks.
        $result = $this->validator()->validate(
            $this->fixture('upload_fake_mime.png', 'image/png'),
            UploadProfile::HeroImage,
            qualityWarningsConfirmed: true
        );

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::UnsupportedType, $result->error);
        self::assertNull($result->safeFilename);
    }

    public function testContentModerationRejectionWinsEvenWithQualityWarningsConfirmed(): void
    {
        $alwaysRejects = new class implements ContentModerationInterface {
            public function moderate(string $filePath, string $mimeType): ContentModerationResult
            {
                return ContentModerationResult::rejected();
            }
        };

        // Deliberately a file that ALSO has a quality warning (small,
        // portrait) — proving moderation's rejection wins outright rather
        // than being reported as "needs confirmation" alongside it.
        $result = $this->validator($alwaysRejects)->validate(
            $this->fixture('upload_hero_tiny_portrait.jpg'),
            UploadProfile::HeroImage,
            qualityWarningsConfirmed: true
        );

        self::assertFalse($result->isValid);
        self::assertSame(UploadValidationError::Rejected, $result->error);
        self::assertNull($result->safeFilename);
    }
}
