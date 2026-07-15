<?php

namespace App\Service;

use App\Entity\MenuImportPage;
use App\Enum\MenuImportPageStatus;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly AIProviderFactory $providerFactory,
        private readonly MenuVisionPromptBuilder $promptBuilder,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {}

    public function analyzePage(MenuImportPage $page): void
    {
        $absolutePath = $this->projectDir . '/public/' . $page->getImagePath();
        if (!is_file($absolutePath)) {
            $page->setStatus(MenuImportPageStatus::FAILED);
            $page->setErrorMessage('Image file not found on disk.');
            $this->em->flush();
            return;
        }

        $imageBytes = file_get_contents($absolutePath);
        $mimeType = (new MimeTypes())->guessMimeType($absolutePath) ?? 'image/jpeg';
        $instructions = $this->promptBuilder->build();

        $page->setStatus(MenuImportPageStatus::ANALYZING);
        $this->em->flush();

        $providers = $this->providerFactory->getAvailableVisionProviders();
        $lastError = null;

        foreach ($providers as $provider) {
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
                continue;
            }
        }

        $page->setStatus(MenuImportPageStatus::FAILED);
        $page->setErrorMessage($lastError?->getMessage() ?? 'No vision-capable AI provider is currently configured.');
        $this->em->flush();
    }
}
