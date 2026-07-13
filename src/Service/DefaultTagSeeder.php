<?php

namespace App\Service;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the predefined system tags for a newly registered restaurant — see
 * the class-level docblock on ProductTag for what "system tag" guarantees.
 *
 * Tag definitions (icons, colours, all translations) come from
 * config/preset_tags.yaml so they can be maintained without touching PHP code.
 */
final class DefaultTagSeeder
{
    private ?array $presets = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {}

    /**
     * @param string[]|null $onlyCodes  If provided, only seed presets whose code is in this list.
     *                                   Pass null (default) to seed every preset in config/preset_tags.yaml.
     */
    public function seedForRestaurant(Restaurant $restaurant, ?array $onlyCodes = null): void
    {
        $defaultLocale = $restaurant->getDefaultLanguage();

        foreach ($this->loadPresets() as $preset) {
            if ($onlyCodes !== null && !in_array($preset['code'], $onlyCodes, true)) {
                continue;
            }

            $tag = new ProductTag($restaurant, $preset['code'], isSystem: true);
            $tag->setIcon($preset['icon']);
            $tag->setColor($preset['color']);
            $tag->setPosition($preset['position']);

            $this->em->persist($tag);

            // Seed all available translations from the YAML file (no AI cost).
            foreach ($preset['translations'] as $locale => $name) {
                $translation = new ProductTagTranslation();
                $translation->setTag($tag);
                $translation->setLocale($locale);
                $translation->setName($name);
                $this->em->persist($translation);
            }

            // If the restaurant's default language has no entry in the YAML,
            // fall back to English so there is always at least one translation.
            if (!isset($preset['translations'][$defaultLocale])
                && isset($preset['translations']['en'])
            ) {
                $fallback = new ProductTagTranslation();
                $fallback->setTag($tag);
                $fallback->setLocale($defaultLocale);
                $fallback->setName($preset['translations']['en']);
                $this->em->persist($fallback);
            }
        }
    }

    private function loadPresets(): array
    {
        if ($this->presets === null) {
            $file = $this->projectDir . '/config/preset_tags.yaml';
            $this->presets = Yaml::parseFile($file)['preset_tags'];
        }

        return $this->presets;
    }
}
