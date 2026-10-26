<?php

namespace App\Tests\Service;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Repository\ProductTagRepository;
use App\Service\AiTagTranslator;
use App\Service\TagTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * resolveForMenu()'s name fallback — requested locale → default language →
 * English → any name — the same order as the menu's ingredient names
 * (Product::ingredientDisplayTranslation()). Before, a tag named only in
 * another language had no name at all in the default language, so it
 * silently vanished from the menu there.
 */
final class TagTranslationServiceFallbackTest extends TestCase
{
    private Restaurant $restaurant;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->restaurant = new Restaurant();
        $this->restaurant->setDefaultLanguage('es');
    }

    private function service(): TagTranslationService
    {
        return new TagTranslationService(
            $this->createStub(ProductTagRepository::class),
            (new \ReflectionClass(AiTagTranslator::class))->newInstanceWithoutConstructor(),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    /** @param array<string, string> $names locale → name */
    private function tag(array $names): ProductTag
    {
        $tag = new ProductTag($this->restaurant, 'tag-' . $this->nextId);
        (new \ReflectionProperty(ProductTag::class, 'id'))->setValue($tag, $this->nextId++);
        foreach ($names as $locale => $name) {
            $t = new ProductTagTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setTag($tag);
            $tag->addTranslation($t);
        }
        $this->restaurant->addProductTag($tag);

        return $tag;
    }

    public function testRequestedLocaleWins(): void
    {
        $tag = $this->tag(['es' => 'Picante', 'en' => 'Spicy', 'fr' => 'Épicé']);

        self::assertSame([$tag->getId() => 'Épicé'], $this->service()->resolveForMenu($this->restaurant, 'fr'));
    }

    public function testFallsBackToTheDefaultLanguageBeforeEnglish(): void
    {
        $tag = $this->tag(['en' => 'Spicy', 'es' => 'Picante']);

        self::assertSame([$tag->getId() => 'Picante'], $this->service()->resolveForMenu($this->restaurant, 'de'));
    }

    public function testATagNamedOnlyInEnglishShowsInTheDefaultLanguage(): void
    {
        $tag = $this->tag(['en' => 'Spicy']);

        self::assertSame([$tag->getId() => 'Spicy'], $this->service()->resolveForMenu($this->restaurant, 'es'));
    }

    public function testEnglishComesBeforeAnyOtherLanguage(): void
    {
        $tag = $this->tag(['fr' => 'Épicé', 'en' => 'Spicy']);

        self::assertSame([$tag->getId() => 'Spicy'], $this->service()->resolveForMenu($this->restaurant, 'es'));
    }

    public function testATagNamedOnlyInAnotherLanguageStillShows(): void
    {
        $tag = $this->tag(['fr' => '', 'it' => 'Piccante']);

        self::assertSame([$tag->getId() => 'Piccante'], $this->service()->resolveForMenu($this->restaurant, 'es'));
    }

    public function testATagWithNoNameAnywhereIsLeftOut(): void
    {
        $this->tag([]);

        self::assertSame([], $this->service()->resolveForMenu($this->restaurant, 'es'));
    }
}
