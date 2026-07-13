<?php

namespace App\Service;

use App\Entity\Allergen;
use App\Entity\AllergenTranslation;
use App\Entity\RegulatoryScheme;
use App\Repository\AllergenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the application-managed allergen taxonomy from config/allergens.yaml
 * (icons, colours, regulatory scheme tags, and translations maintained
 * without touching PHP code — same pattern as DefaultTagSeeder /
 * config/preset_tags.yaml). Upserts by "code", so it's always safe to
 * re-run — e.g. after adding a language column, tweaking a translation, or
 * adding a new regulatory scheme.
 *
 * Unlike DefaultTagSeeder, this seeds one single, global set of rows
 * (Allergen is app-managed and shared by every restaurant, like
 * GlobalIngredient) rather than a per-restaurant copy.
 */
final class AllergenSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AllergenRepository $repository,
        private readonly string $projectDir,
    ) {}

    /** @return array{created: int, updated: int, translationsWritten: int, schemes: string[]} */
    public function seed(): array
    {
        $data = Yaml::parseFile($this->projectDir . '/config/allergens.yaml');

        $created = 0;
        $updated = 0;
        $translationsWritten = 0;
        $schemeCache = [];

        foreach ($data['allergens'] as $row) {
            $allergen = $this->repository->findOneBy(['code' => $row['code']]);
            if ($allergen) {
                $updated++;
            } else {
                $allergen = new Allergen();
                $allergen->setCode($row['code']);
                $this->em->persist($allergen);
                $created++;
            }

            $allergen->setIcon($row['icon'] ?? '');
            $allergen->setColor($row['color'] ?? '#666666');
            $allergen->setPosition($row['position'] ?? 0);

            foreach ($row['translations'] as $locale => $name) {
                $translation = $allergen->getTranslation($locale);
                if (!$translation) {
                    $translation = new AllergenTranslation();
                    $translation->setLocale($locale);
                    $allergen->addTranslation($translation);
                    $this->em->persist($translation);
                }
                $translation->setName($name);
                $translationsWritten++;
            }

            foreach ($row['schemes'] ?? [] as $schemeCode) {
                $scheme = $this->resolveScheme($schemeCode, $schemeCache);
                $allergen->addRegulatoryScheme($scheme);
            }
        }

        $this->em->flush();

        return [
            'created' => $created,
            'updated' => $updated,
            'translationsWritten' => $translationsWritten,
            'schemes' => array_keys($schemeCache),
        ];
    }

    /** @param array<string, RegulatoryScheme> $cache */
    private function resolveScheme(string $code, array &$cache): RegulatoryScheme
    {
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $scheme = $this->em->getRepository(RegulatoryScheme::class)->findOneBy(['code' => $code]);
        if (!$scheme) {
            $scheme = new RegulatoryScheme();
            $scheme->setCode($code);
            $scheme->setName(strtoupper($code));
            $this->em->persist($scheme);
        }

        $cache[$code] = $scheme;

        return $scheme;
    }
}
