<?php

namespace App\Tests\Service;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use App\Enum\MenuImportPageStatus;
use App\Service\AI\AIProviderFactory;
use App\Service\AiAccess;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Service\MenuImportAssembler;
use App\Service\MenuImportExtractionService;
use App\Service\MenuImportLanguageNotice;
use App\Service\MenuImportPipeline;
use App\Service\MenuVisionPromptBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The background menu import (MenuImportPipeline, run by
 * app:menu-import:process-batch) asks AiAccess before any AI call: a batch
 * of a restaurant that's off (expired after paying) fails at once and no
 * request ever leaves (fake HTTP client).
 */
final class MenuImportPipelineAiAccessTest extends TestCase
{
    public function testExpiredRestaurantsBatchFailsWithoutCallingTheAi(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            throw new \LogicException('the AI must not be called');
        });

        $pipeline = new MenuImportPipeline(
            new MenuImportExtractionService(new AIProviderFactory($http, dirname(__DIR__, 2)), new MenuVisionPromptBuilder(), $this->createStub(EntityManagerInterface::class), dirname(__DIR__, 2), sys_get_temp_dir(), new MockClock()),
            (new \ReflectionClass(MenuImportAssembler::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(MenuImportLanguageNotice::class))->newInstanceWithoutConstructor(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new AiAccess($this->createStub(DishPhotoQuota::class)),
        );

        $restaurant = new Restaurant();
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable('today -10 days'));
        self::assertTrue($restaurant->isExpired());
        $batch = new MenuImportBatch($restaurant);
        $page = new MenuImportPage($batch, 'uploads/menu-imports/page.jpg', 0, 'hash');
        $batch->addPage($page);

        $pipeline->processBatch($batch);

        self::assertSame(MenuImportBatchStatus::FAILED, $batch->getStatus());
        self::assertSame(MenuImportPageStatus::PENDING, $page->getStatus());
        self::assertSame(0, $calls);
    }
}
