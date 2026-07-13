<?php

namespace App\Service;

use App\Entity\GlobalIngredientAllergen;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use App\Repository\GlobalIngredientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the starter Global Ingredient → Allergen links from
 * config/global_ingredient_allergens.yaml — see that file for why it is
 * intentionally a small, hand-verified starter set rather than a full
 * mapping of the ~4,700-ingredient library. Upserts by
 * (ingredient code, allergen code), so it is always safe to re-run.
 */
final class GlobalIngredientAllergenSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GlobalIngredientRepository $ingredientRepository,
        private readonly AllergenRepository $allergenRepository,
        private readonly string $projectDir,
    ) {}

    /** @return array{linked: int, updated: int, missingIngredients: string[]} */
    public function seed(): array
    {
        $data = Yaml::parseFile($this->projectDir . '/config/global_ingredient_allergens.yaml');
        $allergensByCode = $this->allergenRepository->findAllIndexedByCode();

        $linked = 0;
        $updated = 0;
        $missingIngredients = [];

        foreach ($data['links'] as $row) {
            $ingredient = $this->ingredientRepository->findOneBy(['code' => $row['ingredient']]);
            if (!$ingredient) {
                $missingIngredients[] = $row['ingredient'];
                continue;
            }

            $allergen = $allergensByCode[$row['allergen']] ?? null;
            if (!$allergen) {
                continue;
            }

            $presence = AllergenPresence::from($row['presence']);

            $link = $ingredient->getAllergenLink($allergen);
            if ($link) {
                $link->setPresence($presence);
                $updated++;
                continue;
            }

            $link = new GlobalIngredientAllergen();
            $link->setAllergen($allergen);
            $link->setPresence($presence);
            $ingredient->addAllergenLink($link);
            $this->em->persist($link);
            $linked++;
        }

        $this->em->flush();

        return ['linked' => $linked, 'updated' => $updated, 'missingIngredients' => array_unique($missingIngredients)];
    }
}
