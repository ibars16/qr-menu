<?php

namespace App\Tests\Command;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Coverage for app:tags:resync-preset-translations: an old preset wording
 * (listed in preset_tags_translation_history.yaml) is updated to the
 * current preset text, an owner-customized name is left alone, and
 * --dry-run writes nothing. Always scoped with --restaurant to this test's
 * own restaurant so other rows in the test DB are never touched.
 */
final class ResyncPresetTagTranslationsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Resync Preset Tags Test Restaurant');
        $this->restaurant->setSlug('resync-preset-tags-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setAdminLocale('es');
        $this->restaurant->setLayout('standard');
        $this->restaurant->setTheme('classic-dark');
        $this->em->persist($this->restaurant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->find(Restaurant::class, $this->restaurant->getId());
        if ($restaurant) {
            foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
                $this->em->remove($tag);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function seedSystemTag(string $code, string $ptName, string $source): void
    {
        $tag = new ProductTag($this->restaurant, $code, true);
        $tag->setIcon('⭐');
        $tag->setColor('#D4AF37');
        $tag->setPosition(0);

        $translation = new ProductTagTranslation();
        $translation->setLocale('pt');
        $translation->setName($ptName);
        $translation->setSource($source);
        $tag->addTranslation($translation);

        $this->restaurant->addProductTag($tag);
        $this->em->persist($tag);
        $this->em->flush();
    }

    private function resync(string $code, bool $dryRun = false): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:tags:resync-preset-translations'));
        $args = ['code' => $code, '--restaurant' => (string) $this->restaurant->getId()];
        if ($dryRun) {
            $args['--dry-run'] = true;
        }
        self::assertSame(0, $tester->execute($args));

        return $tester;
    }

    private function ptTranslation(string $code): ?ProductTagTranslation
    {
        $this->em->clear();
        $tag = $this->em->getRepository(ProductTag::class)->findOneBy([
            'restaurant' => $this->restaurant->getId(),
            'code'       => $code,
        ]);

        return $tag?->getTranslation('pt');
    }

    public function testOldPresetWordingIsUpdated(): void
    {
        $this->seedSystemTag('recommended', 'Nossa recomendação', ProductTagTranslation::SOURCE_PRESET);

        $tester = $this->resync('recommended');

        self::assertStringContainsString('"Nossa recomendação" → "A nossa recomendação"', $tester->getDisplay());
        $pt = $this->ptTranslation('recommended');
        self::assertSame('A nossa recomendação', $pt?->getName());
        self::assertSame(ProductTagTranslation::SOURCE_PRESET, $pt->getSource());
    }

    public function testOwnerCustomizedNameIsKept(): void
    {
        $this->seedSystemTag('contains-dairy', 'Tem leite', ProductTagTranslation::SOURCE_HUMAN);

        $tester = $this->resync('contains-dairy');

        self::assertStringContainsString('keeping customized "Tem leite"', $tester->getDisplay());
        $pt = $this->ptTranslation('contains-dairy');
        self::assertSame('Tem leite', $pt?->getName());
        self::assertSame(ProductTagTranslation::SOURCE_HUMAN, $pt->getSource());
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seedSystemTag('contains-nuts', 'Contém nozes', ProductTagTranslation::SOURCE_PRESET);
        $versionBefore = $this->restaurant->getMenuContentVersion();

        $tester = $this->resync('contains-nuts', dryRun: true);

        self::assertStringContainsString('"Contém nozes" → "Contém frutos de casca rija"', $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        $pt = $this->ptTranslation('contains-nuts');
        self::assertSame('Contém nozes', $pt?->getName());
        self::assertCount(1, $pt->getTag()->getTranslations());
        self::assertSame($versionBefore, $this->em->find(Restaurant::class, $this->restaurant->getId())->getMenuContentVersion());
    }
}
