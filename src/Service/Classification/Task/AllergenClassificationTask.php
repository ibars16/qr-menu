<?php

namespace App\Service\Classification\Task;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use App\Repository\GlobalIngredientRepository;
use App\Service\Classification\ClassificationTaskInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classifies Global Library ingredients against the 14 EU/UK allergen
 * taxonomy — the AI-assisted expansion of the hand-verified starter set in
 * config/global_ingredient_allergens.yaml. Only ever driven by the
 * classify:* console commands via ClassificationRunner; never invoked on a
 * request path, and never touches an ingredient that already has an
 * allergen link (manual, seeded, or previously AI-applied) or an existing
 * ClassificationLog row.
 *
 * Subject = GlobalIngredient. Label = an Allergen::code (closed
 * vocabulary — see validateProposal()). Attributes = {"presence":
 * "contains"|"may_contain"}, mirroring GlobalIngredientAllergen exactly.
 */
final class AllergenClassificationTask implements ClassificationTaskInterface
{
    private const NAME = 'global_ingredient_allergens';
    private const SUBJECT_TYPE = 'global_ingredient';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GlobalIngredientRepository $ingredientRepository,
        private readonly AllergenRepository $allergenRepository,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSubjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function findUnclassified(int $limit): array
    {
        $rows = $this->em->getConnection()->executeQuery(
            'SELECT gi.id
             FROM global_ingredient gi
             WHERE NOT EXISTS (SELECT 1 FROM global_ingredient_allergen gia WHERE gia.global_ingredient_id = gi.id)
               AND NOT EXISTS (
                   SELECT 1 FROM classification_log cl
                   WHERE cl.subject_type = :subjectType AND cl.subject_id = gi.id AND cl.classification_type = :classificationType
               )
             ORDER BY gi.id
             LIMIT :limit',
            ['subjectType' => self::SUBJECT_TYPE, 'classificationType' => self::NAME, 'limit' => $limit],
            ['subjectType' => ParameterType::STRING, 'classificationType' => ParameterType::STRING, 'limit' => ParameterType::INTEGER]
        )->fetchFirstColumn();

        $ids = array_map('intval', $rows);
        if (empty($ids)) {
            return [];
        }

        $byId = [];
        foreach ($this->ingredientRepository->findBy(['id' => $ids]) as $ingredient) {
            $byId[$ingredient->getId()] = $ingredient;
        }

        // Preserve the deterministic id-ascending order from the query
        // above rather than whatever order findBy() happens to return.
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[$id] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function countUnclassified(): int
    {
        return (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*)
             FROM global_ingredient gi
             WHERE NOT EXISTS (SELECT 1 FROM global_ingredient_allergen gia WHERE gia.global_ingredient_id = gi.id)
               AND NOT EXISTS (
                   SELECT 1 FROM classification_log cl
                   WHERE cl.subject_type = :subjectType AND cl.subject_id = gi.id AND cl.classification_type = :classificationType
               )',
            ['subjectType' => self::SUBJECT_TYPE, 'classificationType' => self::NAME],
            ['subjectType' => ParameterType::STRING, 'classificationType' => ParameterType::STRING]
        )->fetchOne();
    }

    public function getSubjectById(int $id): ?object
    {
        return $this->ingredientRepository->find($id);
    }

    /** @param GlobalIngredient $subject */
    public function getSubjectCode(object $subject): string
    {
        return $subject->getCode();
    }

    /** @param GlobalIngredient $subject */
    public function getSubjectDisplayText(object $subject): string
    {
        return $subject->getTranslation('en')?->getName() ?? $subject->getCode();
    }

    public function getInstructions(): string
    {
        return <<<TXT
            You are classifying food ingredients from a restaurant menu platform's shared Global Ingredient Library against the 14 EU/UK standard food allergens.

            For each ingredient, decide which of the listed allergen codes it directly contains as an intrinsic component. Every label you propose must include a "presence" field: "contains" if the ingredient IS that allergen or directly contains it as a component (e.g. Butter contains milk, Shrimp contains crustaceans, Wheat Flour contains gluten), or "may_contain" only if the ingredient is commonly associated with cross-contamination risk for that allergen rather than actually being made from it (e.g. a product commonly processed on shared equipment with tree nuts, or wine's typical sulphite content).

            Worked examples: Butter -> milk, presence contains. Peanut -> peanuts, presence contains. Soy Sauce -> soybeans, presence contains. Wheat Flour -> gluten, presence contains. Plain water, table salt, or plain white sugar -> no labels apply at all.

            Be careful with names that merely resemble an allergen without containing it: "coconut milk", "oat milk", and "soy milk" are plant-based and must NOT be labeled milk. "Peanut butter" contains peanuts, not milk, despite the word "butter". Only propose a label when the ingredient, as commonly understood by a food-safety-conscious cook, genuinely contains or derives from that allergen source — if you are not sure, leave it unlabeled rather than guessing.
            TXT;
    }

    public function getLabelVocabulary(): array
    {
        return array_map(
            static fn ($allergen) => $allergen->getCode(),
            $this->allergenRepository->findAllOrdered()
        );
    }

    public function validateProposal(object $subject, string $label, array $attributes): ?array
    {
        // The "never invent a label" gate: silently discard anything
        // outside the real Allergen taxonomy, even if the AI returned it
        // with high confidence.
        $allergen = $this->allergenRepository->findOneBy(['code' => $label]);
        if (!$allergen) {
            return null;
        }

        $presence = strtolower((string) ($attributes['presence'] ?? 'contains'));
        if (!in_array($presence, ['contains', 'may_contain'], true)) {
            $presence = 'contains';
        }

        return ['presence' => $presence];
    }

    /** @param GlobalIngredient $subject */
    public function apply(object $subject, string $label, array $attributes): void
    {
        $allergen = $this->allergenRepository->findOneBy(['code' => $label]);
        if (!$allergen) {
            return; // defensive — validateProposal() already gates this
        }
        if ($subject->getAllergenLink($allergen)) {
            return; // already linked — never duplicate
        }

        $link = new GlobalIngredientAllergen();
        $link->setAllergen($allergen);
        $link->setPresence(AllergenPresence::from($attributes['presence'] ?? 'contains'));
        $subject->addAllergenLink($link);
        $this->em->persist($link);
    }
}
