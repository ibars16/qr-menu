<?php

namespace App\Tests\Repository;

use App\Entity\OwnerTextTranslation;
use App\Entity\Restaurant;
use App\Repository\OwnerTextTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * OwnerTextTranslationRepository::insertIfMissing(): the same translation
 * stored twice (e.g. two loading screens for the same locale at once) is
 * skipped by ON CONFLICT DO NOTHING — no unique-violation exception, and the
 * connection/EntityManager stay usable for the rest of the request.
 */
final class OwnerTextTranslationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OwnerTextTranslationRepository $repository;
    private ?int $restaurantId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(OwnerTextTranslationRepository::class);
    }

    protected function tearDown(): void
    {
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            $this->em->remove($restaurant); // its owner_text_translation rows go with it (ON DELETE CASCADE)
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testInsertingTheSameTranslationTwiceStoresOneRowWithoutError(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Owner Text Repo Test');
        $restaurant->setSlug('owner-text-repo-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        self::assertTrue($this->repository->insertIfMissing($restaurant, 'de', 'es', 'Copa', 'Glas'));
        self::assertFalse($this->repository->insertIfMissing($restaurant, 'de', 'es', 'Copa', 'Glas (otra)'), 'duplicate skipped, not an error');

        self::assertTrue($this->em->isOpen(), 'the EntityManager is still usable');
        self::assertSame(
            [OwnerTextTranslation::hash('es', 'Copa') => 'Glas'],
            $this->repository->findTranslatedByHash($restaurant, 'de', [OwnerTextTranslation::hash('es', 'Copa')]),
            'one row, the first translation kept',
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM owner_text_translation WHERE restaurant_id = ?',
            [$restaurant->getId()],
        ));
    }
}
