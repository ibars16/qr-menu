<?php

namespace App\Tests\Controller;

use App\Entity\Allergen;
use App\Entity\ClassificationLog;
use App\Entity\Ingredient;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ClassificationStatus;
use App\Service\AllergenBackfill\AllergenReviewQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * POST /admin/ingredients/{id}/allergens: the owner saving an ingredient's
 * allergens marks it reviewed by 'human' and settles what the allergen
 * backfill left pending for it — APPROVED when the AI proposal was kept,
 * REJECTED when it was changed.
 */
final class MenuAdminIngredientAllergensSaveTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Allergen Save Test');
        $restaurant->setSlug('allergen-save-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('allergen-save-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->find($user->getId()));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
        $ingredients = $this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]);
        if ($ingredients !== []) {
            $this->em->createQuery('DELETE FROM ' . ClassificationLog::class . ' l WHERE l.subjectType = :s AND l.subjectId IN (:ids)')
                ->execute(['s' => 'ingredient', 'ids' => array_map(static fn (Ingredient $i) => $i->getId(), $ingredients)]);
        }
        foreach ($ingredients as $ingredient) {
            $this->em->remove($ingredient);
        }
        foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
        $this->em->remove($restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    public function testKeepingTheProposalApprovesIt(): void
    {
        $id = $this->pendingIngredient(['gluten' => 'contains', 'soybeans' => 'contains']);

        $this->save($id, ['soybeans' => 'contains', 'gluten' => 'contains']);

        $log = $this->log($id);
        self::assertSame(ClassificationStatus::APPROVED, $log->getStatus());
        self::assertSame(['gluten' => 'contains', 'soybeans' => 'contains'], $log->getAttributes()['human_result']);
        self::assertSame('human', $this->em->find(Ingredient::class, $id)->getAllergensReviewedBy());
    }

    public function testChangingTheProposalRejectsIt(): void
    {
        $id = $this->pendingIngredient([]);

        $this->save($id, ['milk' => 'may_contain']);

        $log = $this->log($id);
        self::assertSame(ClassificationStatus::REJECTED, $log->getStatus());
        self::assertSame(['milk' => 'may_contain'], $log->getAttributes()['human_result']);
    }

    /** @param array<string, string> $proposal */
    private function pendingIngredient(array $proposal): int
    {
        $ingredient = new Ingredient();
        $ingredient->setCode('allergen-save-test-' . uniqid());
        $ingredient->setRestaurant($this->em->find(Restaurant::class, $this->restaurantId));
        $this->em->persist($ingredient);
        $this->em->flush();

        $log = new ClassificationLog();
        $log->setSubjectType('ingredient');
        $log->setSubjectId($ingredient->getId());
        $log->setClassificationType(AllergenReviewQueue::CLASSIFICATION_TYPE);
        $log->setStatus(ClassificationStatus::PENDING_REVIEW);
        $log->setAttributes(['proposal' => (object) $proposal, 'reasons' => ['test'], 'outcome' => 'review']);
        $this->em->persist($log);
        $this->em->flush();
        $this->em->clear();

        return $ingredient->getId();
    }

    /** @param array<string, string> $allergens code => presence */
    private function save(int $id, array $allergens): void
    {
        $rows = [];
        foreach ($allergens as $code => $presence) {
            $rows[] = ['allergenId' => $this->em->getRepository(Allergen::class)->findOneBy(['code' => $code])->getId(), 'presence' => $presence];
        }
        $this->client->request('POST', "/admin/ingredients/{$id}/allergens", content: json_encode(['allergens' => $rows]));
        self::assertResponseIsSuccessful();
        $this->em->clear();
    }

    private function log(int $id): ClassificationLog
    {
        return $this->em->getRepository(ClassificationLog::class)->findOneBy([
            'subjectType' => 'ingredient', 'subjectId' => $id, 'classificationType' => AllergenReviewQueue::CLASSIFICATION_TYPE,
        ]);
    }
}
