<?php

namespace App\Service\AllergenBackfill;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\Ingredient;
use App\Entity\IngredientAllergen;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One-off data completion: gives every ingredient — Global Library and
 * restaurants' private ones — its allergen links, without changing how the
 * app uses them. Only ever run from app:allergens:backfill.
 *
 * Per ingredient: GroqAllergenClassifier proposes (pass 1) and a separate
 * review call confirms or corrects (pass 2); AllergenKeywordRules checks
 * the name. When both passes agree exactly, everything is "contains", the
 * model isn't unsure and the rules aren't contradicted, that answer is
 * written — links with source 'ai', the ingredient marked reviewed_by
 * 'ai' (also when it carries no allergens at all). Anything doubtful is
 * written too, as the union of every allergen any opinion named
 * (AllergenDecision::APPLIED_UNION — over-warning is the safe mistake),
 * and recorded in AllergenReviewQueue with its reasons. Only a doubtful
 * one where nobody named anything stays unwritten and pending there —
 * which later runs skip, so the daily quota isn't spent asking again.
 *
 * Every write bumps the content version of the menus it changes: the
 * restaurant of a private ingredient, every restaurant with a dish using
 * a global one.
 *
 * Never writes to a "protected" ingredient: one reviewed from the YAML or
 * by a person, or carrying any 'yaml'/'human' link. Those are only
 * classified when asked to (--suggest-protected), and any difference from
 * what they have goes to the review list as a suggestion.
 *
 * recheckApplied() replays the decision for everything already marked
 * reviewed_by 'ai' against the current rules (no AI call: the links it
 * has are what both passes agreed on) — so tightening a rule also covers
 * earlier runs: allergens the rules now expect are added, and an
 * ingredient left with no allergens that would now be doubtful is
 * withdrawn and queued.
 *
 * importReviewList() fills the queue from the review files of runs made
 * before it existed; applyPendingUnion() applies, as the union, what was
 * queued before doubtful ones were applied automatically.
 */
final class IngredientAllergenBackfill
{
    public const KIND_GLOBAL = 'global';
    public const KIND_PRIVATE = 'private';

    /** A quota limit fails every batch after it — stop instead of burning retries; the next run resumes. */
    private const MAX_CONSECUTIVE_FAILED_BATCHES = 3;

    /** @var array<int, true> global ingredients written since the last flush(), for the menu version bump */
    private array $touchedGlobalIds = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AllergenRepository $allergenRepository,
        private readonly GroqAllergenClassifier $classifier,
        private readonly AllergenKeywordRules $rules,
        private readonly AllergenReviewQueue $reviewQueue,
    ) {}

    /**
     * @param list<string>|null $globalCodes null = every global ingredient, [] = none
     * @param list<int>|null    $privateRestaurantIds null = every restaurant, [] = none
     * @param list<string>|null $privateCodes null = every private ingredient of those restaurants
     * @param bool              $includePending also the ones already waiting in AllergenReviewQueue
     * @return list<array{kind: string, id: int, code: string, name: string, locale: string, restaurant: ?string, protected: bool, current: array<string, string>, rules: array{expected: list<string>, forbidden: list<string>, root: string}}>
     */
    public function findSubjects(?array $globalCodes, ?array $privateRestaurantIds, bool $includeProtected, ?int $limit = null, ?array $privateCodes = null, bool $includePending = false): array
    {
        $subjects = [];
        $pending = [
            self::KIND_GLOBAL => $includePending ? [] : $this->reviewQueue->pendingIds(self::KIND_GLOBAL),
            self::KIND_PRIVATE => $includePending ? [] : $this->reviewQueue->pendingIds(self::KIND_PRIVATE),
        ];

        if ($globalCodes !== []) {
            $qb = $this->em->createQueryBuilder()
                ->select('g', 't', 'l', 'a')
                ->from(GlobalIngredient::class, 'g')
                ->leftJoin('g.translations', 't')
                ->leftJoin('g.allergenLinks', 'l')
                ->leftJoin('l.allergen', 'a')
                ->orderBy('g.id');
            if ($globalCodes !== null) {
                $qb->where('g.code IN (:codes)')->setParameter('codes', $globalCodes);
            }
            foreach ($qb->getQuery()->getResult() as $ingredient) {
                $subject = $this->globalSubject($ingredient, $includeProtected);
                if ($subject !== null && !isset($pending[self::KIND_GLOBAL][$subject['id']])) {
                    $subjects[] = $subject;
                }
            }
        }

        if ($privateRestaurantIds !== []) {
            $qb = $this->em->createQueryBuilder()
                ->select('i', 't', 'l', 'a', 'r')
                ->from(Ingredient::class, 'i')
                ->join('i.restaurant', 'r')
                ->leftJoin('i.translations', 't')
                ->leftJoin('i.allergenLinks', 'l')
                ->leftJoin('l.allergen', 'a')
                ->orderBy('i.id');
            if ($privateRestaurantIds !== null) {
                $qb->andWhere('r.id IN (:ids)')->setParameter('ids', $privateRestaurantIds);
            }
            if ($privateCodes !== null) {
                $qb->andWhere('i.code IN (:privateCodes)')->setParameter('privateCodes', $privateCodes);
            }
            foreach ($qb->getQuery()->getResult() as $ingredient) {
                $subject = $this->privateSubject($ingredient, $includeProtected);
                if ($subject !== null && !isset($pending[self::KIND_PRIVATE][$subject['id']])) {
                    $subjects[] = $subject;
                }
            }
        }

        return $limit !== null ? array_slice($subjects, 0, $limit) : $subjects;
    }

    /**
     * @param  list<array<string, mixed>> $subjects from findSubjects()
     * @param  callable(int $done, int $total, ?\Throwable $error): void|null $onBatch
     * @return list<array{subject: array<string, mixed>, first: ?array, second: ?array, decision: AllergenDecision}>
     */
    public function run(array $subjects, int $batchSize, bool $dryRun, ?callable $onBatch = null): array
    {
        // Same root side by side ("egg", "egg white", "free range eggs"),
        // so the model labels variants of one base in the same call.
        usort($subjects, static fn (array $a, array $b) => [$a['rules']['root'], AllergenKeywordRules::normalize($a['name'])]
            <=> [$b['rules']['root'], AllergenKeywordRules::normalize($b['name'])]);

        $validCodes = array_map(static fn ($a) => $a->getCode(), $this->allergenRepository->findAllOrdered());
        $results = [];
        $done = 0;
        $consecutiveFailures = 0;

        foreach (array_chunk($subjects, max(1, $batchSize)) as $batch) {
            // Batch-local ids: global and private ingredient ids overlap.
            $items = [];
            foreach ($batch as $i => $subject) {
                $items[$i + 1] = ['name' => $subject['name'], 'locale' => $subject['locale']];
            }

            $error = null;
            try {
                $first = $this->classifier->classify($items);
                $second = $this->classifier->review($items, $first);
            } catch (\Throwable $e) {
                $error = $e;
                $first = $second = [];
            }

            foreach ($batch as $i => $subject) {
                $decision = AllergenDecision::decide(
                    $first[$i + 1] ?? null,
                    $second[$i + 1] ?? null,
                    $subject['rules'],
                    $validCodes,
                    $subject['protected'] ? $subject['current'] : null,
                );
                if (!$dryRun) {
                    $this->apply($subject, $decision, $first[$i + 1]['allergens'] ?? null, $second[$i + 1]['allergens'] ?? null);
                    if (in_array($decision->outcome, [AllergenDecision::REVIEW, AllergenDecision::SUGGESTION], true)) {
                        $this->reviewQueue->enqueue($subject, $decision, $first[$i + 1]['allergens'] ?? null);
                    }
                }
                $results[] = ['subject' => $subject, 'first' => $first[$i + 1] ?? null, 'second' => $second[$i + 1] ?? null, 'decision' => $decision];
            }

            if (!$dryRun) {
                $this->flush();
            }
            $done += count($batch);
            if ($onBatch) {
                $onBatch($done, count($subjects), $error);
            }

            $consecutiveFailures = $error ? $consecutiveFailures + 1 : 0;
            if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILED_BATCHES) {
                break;
            }
        }

        return $results;
    }

    /** @return array{calls: int, promptTokens: int, completionTokens: int} */
    public function getUsage(): array
    {
        return $this->classifier->getUsage();
    }

    /**
     * @param list<string>|null $globalCodes          null = every global ingredient, [] = none
     * @param list<int>|null    $privateRestaurantIds null = every restaurant, [] = none
     * @return list<array{subject: array<string, mixed>, first: ?array, second: ?array, decision: AllergenDecision}>
     *   REVIEW = withdrawn (links removed, back to "never reviewed"); APPLIED/NO_ALLERGENS = kept as is;
     *   APPLIED_UNION = kept, plus whatever the union adds (missing allergens, "may contain" → "contains")
     */
    public function recheckApplied(bool $dryRun, ?array $globalCodes = null, ?array $privateRestaurantIds = null): array
    {
        $subjects = [];
        if ($globalCodes !== []) {
            $qb = $this->em->createQueryBuilder()
                ->select('g', 't', 'l', 'a')
                ->from(GlobalIngredient::class, 'g')
                ->leftJoin('g.translations', 't')
                ->leftJoin('g.allergenLinks', 'l')
                ->leftJoin('l.allergen', 'a')
                ->where('g.allergensReviewedBy = :ai')
                ->setParameter('ai', GlobalIngredientAllergen::SOURCE_AI)
                ->orderBy('g.id');
            if ($globalCodes !== null) {
                $qb->andWhere('g.code IN (:codes)')->setParameter('codes', $globalCodes);
            }
            foreach ($qb->getQuery()->getResult() as $ingredient) {
                $subjects[] = $this->globalSubject($ingredient, false, true);
            }
        }
        if ($privateRestaurantIds !== []) {
            $qb = $this->em->createQueryBuilder()
                ->select('i', 't', 'l', 'a', 'r')
                ->from(Ingredient::class, 'i')
                ->join('i.restaurant', 'r')
                ->leftJoin('i.translations', 't')
                ->leftJoin('i.allergenLinks', 'l')
                ->leftJoin('l.allergen', 'a')
                ->where('i.allergensReviewedBy = :ai')
                ->setParameter('ai', IngredientAllergen::SOURCE_AI)
                ->orderBy('i.id');
            if ($privateRestaurantIds !== null) {
                $qb->andWhere('r.id IN (:ids)')->setParameter('ids', $privateRestaurantIds);
            }
            foreach ($qb->getQuery()->getResult() as $ingredient) {
                $subjects[] = $this->privateSubject($ingredient, false, true);
            }
        }

        $validCodes = array_map(static fn ($a) => $a->getCode(), $this->allergenRepository->findAllOrdered());
        $results = [];
        foreach (array_filter($subjects) as $subject) {
            $agreed = ['allergens' => $subject['current'], 'unsure' => false];
            $decision = AllergenDecision::decide($agreed, $agreed, $subject['rules'], $validCodes, null);
            if ($decision->outcome === AllergenDecision::REVIEW) {
                $decision = new AllergenDecision(AllergenDecision::REVIEW, $decision->allergens, array_merge(
                    ['DESAPLICADO — se había aplicado y con las reglas actuales va a revisión'],
                    $decision->reasons,
                ));
                if (!$dryRun) {
                    $this->withdraw($subject);
                    $this->reviewQueue->enqueue($subject, $decision, $subject['current']);
                }
            } elseif ($decision->outcome === AllergenDecision::APPLIED_UNION && !$dryRun) {
                $ingredient = $this->entity($subject);
                if ($this->writeLinks($ingredient, $decision->allergens)) {
                    $this->reviewQueue->recordAppliedUnion($subject, $decision, $subject['current'], $subject['current']);
                    $this->touched($ingredient);
                }
            }
            $results[] = ['subject' => $subject, 'first' => $agreed, 'second' => $agreed, 'decision' => $decision];
        }
        if (!$dryRun) {
            $this->flush();
        }

        return $results;
    }

    /**
     * Applies, as the union (both passes + what the keyword rules expect),
     * every doubtful ingredient still pending in AllergenReviewQueue —
     * queued before doubtful ones were applied automatically. No AI call.
     * An empty union stays pending; one reviewed or protected since is skipped.
     *
     * @return list<array{subject: array<string, mixed>, first: ?array, second: ?array, decision: AllergenDecision}>
     *   APPLIED_UNION = applied (or would be, on a dry run); REVIEW = empty union, left pending
     */
    public function applyPendingUnion(bool $dryRun): array
    {
        $validCodes = array_map(static fn ($a) => $a->getCode(), $this->allergenRepository->findAllOrdered());
        $results = [];
        foreach ($this->reviewQueue->findPendingReviews() as $log) {
            $kind = AllergenReviewQueue::kindOf($log->getSubjectType());
            $ingredient = $kind === self::KIND_GLOBAL
                ? $this->em->find(GlobalIngredient::class, $log->getSubjectId())
                : $this->em->find(Ingredient::class, $log->getSubjectId());
            $subject = match (true) {
                $ingredient instanceof GlobalIngredient => $this->globalSubject($ingredient, false),
                $ingredient instanceof Ingredient => $this->privateSubject($ingredient, false),
                default => null,
            };
            if ($subject === null) {
                continue;
            }

            $attributes = $log->getAttributes();
            $first = isset($attributes['first_pass']) ? (array) $attributes['first_pass'] : null;
            $second = (array) ($attributes['proposal'] ?? []);
            $union = AllergenDecision::union([$first, $second], $subject['rules']['expected'], $validCodes);
            $decision = new AllergenDecision($union === [] ? AllergenDecision::REVIEW : AllergenDecision::APPLIED_UNION, $union, $attributes['reasons'] ?? []);
            if (!$dryRun && $union !== []) {
                $this->apply($subject, $decision, $first, $second);
            }
            $results[] = ['subject' => $subject, 'first' => $first !== null ? ['allergens' => $first] : null, 'second' => ['allergens' => $second], 'decision' => $decision];
        }
        if (!$dryRun) {
            $this->flush();
        }

        return $results;
    }

    /**
     * @param array<string, mixed>       $subject
     * @param array<string, string>|null $first  the first pass's answer, for the APPLIED_UNION record
     * @param array<string, string>|null $second the review pass's answer, idem
     */
    private function apply(array $subject, AllergenDecision $decision, ?array $first = null, ?array $second = null): void
    {
        if (!in_array($decision->outcome, [AllergenDecision::APPLIED, AllergenDecision::NO_ALLERGENS, AllergenDecision::APPLIED_UNION], true) || $subject['protected']) {
            return;
        }

        $ingredient = $this->entity($subject);
        if ($ingredient === null || $ingredient->getAllergensReviewedAt() !== null) {
            return;
        }
        $this->writeLinks($ingredient, $decision->allergens);
        $ingredient->markAllergensReviewed(GlobalIngredientAllergen::SOURCE_AI);
        if ($decision->outcome === AllergenDecision::APPLIED_UNION) {
            $this->reviewQueue->recordAppliedUnion($subject, $decision, $first, $second);
        } else {
            $this->reviewQueue->resolveApplied($subject);
        }
        $this->touched($ingredient);
    }

    /** @param array<string, mixed> $subject */
    private function entity(array $subject): GlobalIngredient|Ingredient|null
    {
        return $subject['kind'] === self::KIND_GLOBAL
            ? $this->em->find(GlobalIngredient::class, $subject['id'])
            : $this->em->find(Ingredient::class, $subject['id']);
    }

    /**
     * Adds the missing 'ai' links and raises "may contain" to "contains"
     * where asked — never removes or lowers anything.
     *
     * @param array<string, string> $allergens code => presence
     * @return bool whether anything changed
     */
    private function writeLinks(GlobalIngredient|Ingredient $ingredient, array $allergens): bool
    {
        $byCode = $this->allergenRepository->findAllIndexedByCode();
        $changed = false;
        foreach ($allergens as $code => $presence) {
            $existing = $ingredient->getAllergenLink($byCode[$code]);
            if ($existing !== null) {
                if ($presence === AllergenPresence::CONTAINS->value && $existing->getPresence() !== AllergenPresence::CONTAINS) {
                    $existing->setPresence(AllergenPresence::CONTAINS);
                    $changed = true;
                }
                continue;
            }
            $link = $ingredient instanceof GlobalIngredient ? new GlobalIngredientAllergen() : new IngredientAllergen();
            $link->setAllergen($byCode[$code]);
            $link->setPresence(AllergenPresence::from($presence));
            $link->setSource(GlobalIngredientAllergen::SOURCE_AI);
            $ingredient->addAllergenLink($link);
            $this->em->persist($link);
            $changed = true;
        }

        return $changed;
    }

    /** Its allergens changed: so did the computed allergens of every dish using it. */
    private function touched(GlobalIngredient|Ingredient $ingredient): void
    {
        if ($ingredient instanceof Ingredient) {
            $ingredient->getRestaurant()->bumpMenuContentVersion();
        } else {
            $this->touchedGlobalIds[$ingredient->getId()] = true;
        }
    }

    /** flush(), plus the version bump of every restaurant with a dish using a global ingredient written since the last one. */
    private function flush(): void
    {
        $this->em->flush();
        if ($this->touchedGlobalIds === []) {
            return;
        }
        $restaurantIds = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(c.restaurant)')
            ->from(Product::class, 'p')
            ->join('p.category', 'c')
            ->join('p.globalIngredientLinks', 'l')
            ->where('IDENTITY(l.globalIngredient) IN (:ids)')
            ->setParameter('ids', array_keys($this->touchedGlobalIds))
            ->getQuery()->getSingleColumnResult();
        $this->touchedGlobalIds = [];
        foreach ($restaurantIds as $id) {
            $this->em->find(Restaurant::class, $id)?->bumpMenuContentVersion();
        }
        $this->em->flush();
    }

    /**
     * Queues the review items of runs made before AllergenReviewQueue
     * existed, from their -todo.csv rows (the latest row per ingredient).
     * Rows withdrawn by an earlier --recheck-applied ("DESAPLICADO") were
     * agreed AI answers, so they are decided again with the current rules
     * and re-applied when those now allow it; everything else is queued as
     * it was recorded. An ingredient that has been reviewed since, or is
     * already queued, is left alone.
     *
     * @param  list<array<string, string>> $rows CSV rows (header => value) with resultado review/suggestion
     * @return array{queued: int, reapplied: int, skipped: int}
     */
    public function importReviewList(array $rows, bool $dryRun): array
    {
        $counts = ['queued' => 0, 'reapplied' => 0, 'skipped' => 0];
        $pending = [self::KIND_GLOBAL => $this->reviewQueue->pendingIds(self::KIND_GLOBAL), self::KIND_PRIVATE => $this->reviewQueue->pendingIds(self::KIND_PRIVATE)];
        $validCodes = array_map(static fn ($a) => $a->getCode(), $this->allergenRepository->findAllOrdered());

        foreach ($rows as $row) {
            $kind = $row['tipo'] === 'global' ? self::KIND_GLOBAL : self::KIND_PRIVATE;
            $id = (int) $row['id'];
            $subject = null;
            if (!isset($pending[$kind][$id])) {
                $subject = $kind === self::KIND_GLOBAL
                    ? (($g = $this->em->find(GlobalIngredient::class, $id)) ? $this->globalSubject($g, true) : null)
                    : (($i = $this->em->find(Ingredient::class, $id)) ? $this->privateSubject($i, true) : null);
            }
            // Gone, reviewed since, or a review item that has become protected.
            if ($subject === null || ($subject['protected'] && $row['resultado'] !== AllergenDecision::SUGGESTION)) {
                $counts['skipped']++;
                continue;
            }

            $proposal = AllergenDecision::parse($row['propuesta_revision']) ?? [];
            if (str_starts_with($row['motivo'], 'DESAPLICADO') && !$subject['protected']) {
                $agreed = ['allergens' => $proposal, 'unsure' => false];
                $decision = AllergenDecision::decide($agreed, $agreed, $subject['rules'], $validCodes, null);
                // A doubtful one (APPLIED_UNION) is queued like the rest; applyPendingUnion() applies it.
                if (in_array($decision->outcome, [AllergenDecision::APPLIED, AllergenDecision::NO_ALLERGENS], true)) {
                    if (!$dryRun) {
                        $this->apply($subject, $decision);
                    }
                    $counts['reapplied']++;
                    continue;
                }
                $decision = new AllergenDecision(AllergenDecision::REVIEW, $proposal, array_merge(
                    ['DESAPLICADO — se había aplicado y con las reglas actuales va a revisión'],
                    $decision->reasons,
                ));
            } else {
                $decision = new AllergenDecision($row['resultado'], $proposal, explode(' · ', $row['motivo']));
            }

            if (!$dryRun) {
                $this->reviewQueue->enqueue($subject, $decision, AllergenDecision::parse($row['propuesta_1a_pasada']));
            }
            $pending[$kind][$id] = true;
            $counts['queued']++;
        }
        if (!$dryRun) {
            $this->flush();
        }

        return $counts;
    }

    /** @param array<string, mixed> $subject an AI-reviewed, unprotected one: every link it has is 'ai' */
    private function withdraw(array $subject): void
    {
        $ingredient = $subject['kind'] === self::KIND_GLOBAL
            ? $this->em->find(GlobalIngredient::class, $subject['id'])
            : $this->em->find(Ingredient::class, $subject['id']);
        foreach ($ingredient->getAllergenLinks()->toArray() as $link) {
            $ingredient->removeAllergenLink($link);
        }
        $ingredient->clearAllergensReview();
        $this->touched($ingredient);
    }

    /**
     * @param bool $aiReviewedOnly recheckApplied(): only unprotected ones already reviewed by 'ai'
     * @return array<string, mixed>|null
     */
    private function globalSubject(GlobalIngredient $ingredient, bool $includeProtected, bool $aiReviewedOnly = false): ?array
    {
        $current = [];
        $hasProtectedLink = false;
        foreach ($ingredient->getAllergenLinks() as $link) {
            $current[$link->getAllergen()->getCode()] = $link->getPresence()->value;
            $hasProtectedLink = $hasProtectedLink || $link->getSource() !== GlobalIngredientAllergen::SOURCE_AI;
        }
        ksort($current);

        $reviewedBy = $ingredient->getAllergensReviewedBy();
        $protected = $hasProtectedLink || in_array($reviewedBy, [GlobalIngredientAllergen::SOURCE_YAML, GlobalIngredientAllergen::SOURCE_HUMAN], true);
        if ($aiReviewedOnly ? $protected || $reviewedBy !== GlobalIngredientAllergen::SOURCE_AI : ($protected ? !$includeProtected : $reviewedBy !== null)) {
            return null;
        }

        $name = $ingredient->getTranslation('en')?->getName() ?? str_replace('-', ' ', $ingredient->getCode());

        return [
            'kind' => self::KIND_GLOBAL,
            'id' => $ingredient->getId(),
            'code' => $ingredient->getCode(),
            'name' => $name,
            'locale' => 'en',
            'restaurant' => null,
            'protected' => $protected,
            'current' => $current,
            'rules' => $this->rules->evaluate($name),
        ];
    }

    /**
     * @param bool $aiReviewedOnly see globalSubject()
     * @return array<string, mixed>|null
     */
    private function privateSubject(Ingredient $ingredient, bool $includeProtected, bool $aiReviewedOnly = false): ?array
    {
        $current = [];
        $hasProtectedLink = false;
        foreach ($ingredient->getAllergenLinks() as $link) {
            $current[$link->getAllergen()->getCode()] = $link->getPresence()->value;
            $hasProtectedLink = $hasProtectedLink || $link->getSource() !== IngredientAllergen::SOURCE_AI;
        }
        ksort($current);

        $reviewedBy = $ingredient->getAllergensReviewedBy();
        $protected = $hasProtectedLink || $reviewedBy === IngredientAllergen::SOURCE_HUMAN;
        if ($aiReviewedOnly ? $protected || $reviewedBy !== IngredientAllergen::SOURCE_AI : ($protected ? !$includeProtected : $reviewedBy !== null)) {
            return null;
        }

        // The name in the language the owner actually wrote it in: the
        // restaurant's default language when there is one, otherwise the
        // only/first one there is (e.g. a Catalan-only "ametllat").
        $defaultLocale = $ingredient->getRestaurant()->getDefaultLanguage();
        $name = null;
        $locale = $defaultLocale;
        foreach ($ingredient->getTranslations() as $translation) {
            if (trim($translation->getName()) === '') {
                continue;
            }
            if ($translation->getLocale() === $defaultLocale) {
                $name = $translation->getName();
                $locale = $defaultLocale;
                break;
            }
            if ($name === null) {
                $name = $translation->getName();
                $locale = $translation->getLocale();
            }
        }
        $name ??= str_replace('-', ' ', $ingredient->getCode());

        return [
            'kind' => self::KIND_PRIVATE,
            'id' => $ingredient->getId(),
            'code' => $ingredient->getCode(),
            'name' => $name,
            'locale' => $locale,
            'restaurant' => $ingredient->getRestaurant()->getSlug(),
            'protected' => $protected,
            'current' => $current,
            'rules' => $this->rules->evaluate($name),
        ];
    }
}
