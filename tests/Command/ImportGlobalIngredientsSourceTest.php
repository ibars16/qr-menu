<?php

namespace App\Tests\Command;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:global-ingredients:import marks every CSV name as source 'dataset',
 * upgrades an AI name the CSV now carries, and never overwrites a
 * hand-written ('human') one. Run with --skip-ai-backfill: no AI call.
 */
final class ImportGlobalIngredientsSourceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $code;
    private string $csv;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->code = 'test-import-source-' . uniqid();
        $this->csv = tempnam(sys_get_temp_dir(), 'global-ingredients-') . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        $this->em->clear();
        $ingredient = $this->em->getRepository(GlobalIngredient::class)->findOneBy(['code' => $this->code]);
        if ($ingredient) {
            $this->em->remove($ingredient);
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testCsvNamesAreDatasetAndHandWrittenOnesSurvive(): void
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode($this->code);
        foreach (['es' => ['Albahaca IA', GlobalIngredientTranslation::SOURCE_AI], 'fr' => ['Basilic (main)', GlobalIngredientTranslation::SOURCE_HUMAN]] as $locale => [$name, $source]) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);
        $this->em->flush();
        $this->em->clear();

        file_put_contents($this->csv, "code,en,es,fr\n{$this->code},Basil,Albahaca,Basilic\n");
        $tester = new CommandTester((new Application(self::$kernel))->find('app:global-ingredients:import'));
        $tester->execute(['--file' => $this->csv, '--skip-ai-backfill' => true]);
        $tester->assertCommandIsSuccessful();

        $rows = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT t.locale, t.name, t.source FROM global_ingredient_translation t JOIN global_ingredient g ON g.id = t.global_ingredient_id WHERE g.code = ? ORDER BY t.locale',
            [$this->code],
        );
        self::assertSame([
            ['locale' => 'en', 'name' => 'Basil', 'source' => 'dataset'],
            ['locale' => 'es', 'name' => 'Albahaca', 'source' => 'dataset'],
            ['locale' => 'fr', 'name' => 'Basilic (main)', 'source' => 'human'],
        ], $rows);
    }
}
