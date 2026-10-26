<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Maison's chef's-recommendation banner (standard/show.html.twig hero,
 * layouts/standard/_banner_text.html.twig): a multiprice dish shows "Desde" +
 * its lowest price on one line, not the full price stack.
 */
final class MaisonBannerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            $this->em->flush();
            foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
                $this->em->remove($tag);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /**
     * A maison restaurant with one photo dish (base price 12,00 € labelled
     * "Botella" plus the given variants, in cents) carrying the
     * 'recommended' system tag.
     *
     * @param int[] $variantPrices
     */
    private function makeRecommendedMultipriceDish(array $variantPrices): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Maison Banner Test');
        $restaurant->setSlug('maison-banner-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);

        $categoryTranslation = new CategoryTranslation();
        $categoryTranslation->setLocale('es');
        $categoryTranslation->setName('Entrantes');
        $category->addTranslation($categoryTranslation);
        $this->em->persist($categoryTranslation);

        $tag = new ProductTag($restaurant, 'recommended', true);
        $tagTranslation = new ProductTagTranslation();
        $tagTranslation->setLocale('es');
        $tagTranslation->setName('Nuestra recomendación');
        $tag->addTranslation($tagTranslation);
        $this->em->persist($tag);

        $product = new Product();
        $product->setBasePrice(1200);
        $product->setBasePriceLabel('Botella');
        $product->setImage('banner-test.jpg');
        $product->setActive(true);
        $category->addProduct($product);
        $product->addTag($tag);
        foreach ($variantPrices as $i => $price) {
            $variant = new ProductPriceVariant();
            $variant->setLabel('Variante ' . $i);
            $variant->setPrice($price);
            $variant->setPosition($i);
            $product->addPriceVariant($variant);
        }
        $this->em->persist($product);

        $productTranslation = new ProductTranslation();
        $productTranslation->setLocale('es');
        $productTranslation->setName('Plato de prueba');
        $product->addTranslation($productTranslation);
        $this->em->persist($productTranslation);

        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();
        $this->em->clear();

        return $restaurant;
    }

    public function testMaisonBannerWithMultipriceShowsFromAndTheLowestPrice(): void
    {
        $restaurant = $this->makeRecommendedMultipriceDish([2210, 450]);

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es&currency=EUR');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('.banner');
        self::assertCount(1, $banner);
        self::assertSame('Desde 4,50€', preg_replace('/\s+/', ' ', trim($banner->filter('.bsub')->text())));
        self::assertCount(0, $banner->filter('.price-stack'), 'the banner shows only the lowest price');
        self::assertSame('Ver detalle de Plato de prueba', $banner->attr('aria-label'));
    }
}
