<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Tests\Support\TestUploadsDir;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Maison's hero photo band (standard/show.html.twig, image_size()): a photo
 * narrower than 2560px gets --fit (its real size as --nw/--nh plus a
 * blurred .cover-backdrop) so the band never draws it above 1:1; a wider
 * one, or one whose size can't be read, keeps exactly the plain markup.
 */
final class MaisonHeroPhotoFitTest extends WebTestCase
{
    use TestUploadsDir;

    private const FIXTURES_DIR = __DIR__ . '/../fixtures';

    /** The band exactly as it rendered before --fit existed. */
    private const PLAIN_BAND = <<<'HTML'
        <div class="hero-photo-band">
                        <img src="/uploads/heroes/%s" alt="Maison Hero Fit Test">
                    </div>
        HTML;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $heroDir;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    /** @var string[] */
    private array $heroFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->heroDir = self::testUploadsDir('heroes') . '/heroes';
    }

    protected function tearDown(): void
    {
        foreach ($this->heroFilesToRemove as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if ($restaurant) {
                $this->em->remove($restaurant);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** A maison restaurant whose hero photo is a copy of $fixture (null: a filename with no file behind it). */
    private function makeMaisonWithHero(?string $fixture): Restaurant
    {
        $filename = bin2hex(random_bytes(16)) . '.jpg';
        if ($fixture !== null) {
            copy(self::FIXTURES_DIR . '/' . $fixture, $this->heroDir . '/' . $filename);
            $this->heroFilesToRemove[] = $this->heroDir . '/' . $filename;
        }

        $restaurant = new Restaurant();
        $restaurant->setName('Maison Hero Fit Test');
        $restaurant->setSlug('maison-hero-fit-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $restaurant->setHeroImage($filename);
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function bandHtml(Restaurant $restaurant): string
    {
        $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es&currency=EUR');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('#<div class="hero-photo-band.*?<img [^>]*>\s*</div>#s', $html, $m), 'the band is rendered');

        return $m[0];
    }

    public function testSmallPhotoGetsItsRealSizeAndABlurredBackdrop(): void
    {
        // 800x400 — narrower than the 768–1023 band (up to ~1000px).
        $restaurant = $this->makeMaisonWithHero('upload_hero_ok.jpg');
        $src = '/uploads/heroes/' . $restaurant->getHeroImage();

        $band = $this->bandHtml($restaurant);

        self::assertStringContainsString('class="hero-photo-band hero-photo-band--fit"', $band);
        self::assertStringContainsString('<div class="cover-backdrop" style="background-image: url(\'' . $src . '\')" aria-hidden="true"></div>', $band);
        self::assertStringContainsString('<img src="' . $src . '" alt="Maison Hero Fit Test" style="--nw: 800px; --nh: 400px">', $band);
    }

    public function testPhoto2560WideOrMoreKeepsThePlainMarkup(): void
    {
        $restaurant = $this->makeMaisonWithHero('upload_hero_2560.jpg');

        self::assertSame(sprintf(self::PLAIN_BAND, $restaurant->getHeroImage()), $this->bandHtml($restaurant));
    }

    public function testUnreadablePhotoKeepsThePlainMarkup(): void
    {
        $restaurant = $this->makeMaisonWithHero(null);

        self::assertSame(sprintf(self::PLAIN_BAND, $restaurant->getHeroImage()), $this->bandHtml($restaurant));
    }
}
