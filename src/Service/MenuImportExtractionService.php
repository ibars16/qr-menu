<?php

namespace App\Service;

use App\Entity\MenuImportPage;
use App\Enum\MenuImportPageStatus;
use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mime\MimeTypes;

/**
 * Phase 2, and only Phase 2: reads an already-uploaded page's file from
 * disk, sends it to a vision provider, and writes the raw result back onto
 * that same MenuImportPage row. This is the only place in the menu-import
 * feature that writes to the database right now — no Product, Category,
 * Ingredient, or ProductTag is created here, deliberately. Turning this
 * page's extractedData into real menu rows is a later phase's job, and it
 * doesn't happen by calling this class.
 */
final class MenuImportExtractionService
{
    /**
     * Seconds to wait before the 2nd and the 3rd attempt (so at most 3
     * attempts per provider), only after a temporary failure — Google
     * "high demand" (503), rate limit (429), timeout or network error.
     * Those answers process nothing, so they cost nothing; a malformed
     * answer is never retried. After a timeout (where Google may have
     * worked, and charged), at most one more attempt.
     */
    private const RETRY_WAITS = [3, 8];

    /**
     * Prefix of a page's errorMessage when it failed only for a temporary
     * reason: the end screen then says "saturated, your photo is fine"
     * instead of blaming the photo (MenuImportPage::isTemporaryFailure()).
     */
    public const TEMPORARY_FAILURE_PREFIX = 'Temporary: ';

    public function __construct(
        private readonly AIProviderFactory $providerFactory,
        private readonly MenuVisionPromptBuilder $promptBuilder,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly string $uploadsDir,
        private readonly ClockInterface $clock,
    ) {}

    public function analyzePage(MenuImportPage $page): void
    {
        // imagePath is stored relative to public/ ("uploads/menu-imports/…");
        // the uploads part lives wherever app.uploads_dir points.
        $imagePath    = $page->getImagePath();
        $absolutePath = str_starts_with($imagePath, 'uploads/')
            ? $this->uploadsDir . substr($imagePath, strlen('uploads'))
            : $this->projectDir . '/public/' . $imagePath;
        if (!is_file($absolutePath)) {
            $page->setStatus(MenuImportPageStatus::FAILED);
            $page->setErrorMessage('Image file not found on disk.');
            $this->em->flush();
            return;
        }

        $imageBytes = file_get_contents($absolutePath);
        $mimeType = (new MimeTypes())->guessMimeType($absolutePath) ?? 'image/jpeg';
        $instructions = $this->promptBuilder->build($page->getBatch()->getRestaurant()->getDefaultLanguage());

        $page->setStatus(MenuImportPageStatus::ANALYZING);
        $this->em->flush();

        $providers = $this->providerFactory->getAvailableVisionProviders();
        $lastError = null;

        foreach ($providers as $provider) {
            $attempt = 1;
            $maxAttempts = count(self::RETRY_WAITS) + 1;

            while (true) {
                try {
                    $result = $provider->analyzeMenuPage($imageBytes, $mimeType, $instructions);

                    $page->setExtractedData($result->data);
                    $page->setDetectedLocale(is_string($result->data['detected_language'] ?? null) ? $result->data['detected_language'] : null);
                    $page->setStatus(MenuImportPageStatus::EXTRACTED);
                    $page->setErrorMessage(null);
                    $this->em->flush();

                    return;
                } catch (AIProviderException $e) {
                    $lastError = $e;
                    if (!self::isTemporary($e)) {
                        break;
                    }
                    if ($e->reason === AIFailureReason::TIMEOUT) {
                        $maxAttempts = min($maxAttempts, $attempt + 1);
                    }
                    if ($attempt >= $maxAttempts) {
                        break;
                    }
                    $this->clock->sleep(self::RETRY_WAITS[$attempt - 1]);
                    $attempt++;
                }
            }
        }

        $page->setStatus(MenuImportPageStatus::FAILED);
        $message = $lastError?->getMessage() ?? 'No vision-capable AI provider is currently configured.';
        $page->setErrorMessage($lastError !== null && self::isTemporary($lastError) ? self::TEMPORARY_FAILURE_PREFIX . $message : $message);
        $this->em->flush();
    }

    /** Google overloaded or briefly unreachable — worth trying again later. */
    private static function isTemporary(AIProviderException $e): bool
    {
        return match ($e->reason) {
            AIFailureReason::TIMEOUT, AIFailureReason::RATE_LIMITED, AIFailureReason::NETWORK_ERROR => true,
            AIFailureReason::HTTP_ERROR => in_array($e->httpStatus, [429, 503], true),
            default => false,
        };
    }
}
