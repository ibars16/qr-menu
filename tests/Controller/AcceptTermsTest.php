<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\LegalInfo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * The required "He leído y acepto los Términos de Servicio y la Política de
 * Privacidad" box of the three sign-ups (/register, /alta/{code},
 * /partners/registro): without it nothing is created and the form says
 * why; with it the new account keeps when it accepted and which terms
 * (their date in config/legal.yaml, LegalInfo::termsVersion()).
 */
final class AcceptTermsTest extends WebTestCase
{
    private const string REQUIRED = 'Para crear la cuenta tienes que aceptar los términos y la política de privacidad.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->reseller = new Reseller('Accept Terms Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Users, then restaurants, then resellers: the foreign keys are RESTRICT.
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'accept-terms-test-%\'');
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', 'accept-terms-test-%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Accept Terms Test%'");

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function restaurantSignups(): iterable
    {
        yield 'register' => ['/register'];
        yield 'reseller link' => ['alta'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restaurantSignups')]
    public function testRestaurantSignupNeedsTheBox(string $path): void
    {
        $email = 'accept-terms-test-' . uniqid() . '@example.test';
        $form = $this->restaurantForm($path, $email);

        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame([self::REQUIRED], $page->filter('form > .accept-terms-errors[role="alert"] li')->each(fn ($li) => $li->text()));
        $box = $page->filter('input[name="registration_form[acceptTerms]"]');
        self::assertSame('true', $box->attr('aria-invalid'));
        // The message is at the top of the form, before the first section.
        self::assertSame($box->attr('aria-describedby'), $page->filter('form > .errors')->first()->attr('id'));
        self::assertCount(0, $page->filter('.accept-terms .errors'));
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restaurantSignups')]
    public function testRestaurantSignupSavesWhenAndWhichTerms(string $path): void
    {
        $email = 'accept-terms-test-' . uniqid() . '@example.test';
        $form = $this->restaurantForm($path, $email);
        $form['registration_form[acceptTerms]']->tick();

        $before = new \DateTimeImmutable('-1 second');
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/bienvenida');
        $this->assertAccepted($email, $before);
    }

    public function testResellerSignupNeedsTheBoxAndSavesWhenAndWhichTerms(): void
    {
        $email = 'accept-terms-test-' . uniqid() . '@example.test';
        $form = $this->client->request('GET', '/partners/registro')->filter('form')->form();
        $form['reseller_registration_form[company]'] = 'Accept Terms Test Reseller';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = $email;
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';

        $page = $this->client->submit($form);
        self::assertResponseIsUnprocessable();
        self::assertSame([self::REQUIRED], $page->filter('form > .accept-terms-errors[role="alert"] li')->each(fn ($li) => $li->text()));
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));

        $form = $page->filter('form')->form();
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';
        $form['reseller_registration_form[acceptTerms]']->tick();
        $before = new \DateTimeImmutable('-1 second');
        $this->client->submit($form);

        self::assertResponseRedirects('/partners');
        $this->assertAccepted($email, $before);
    }

    public function testTheBoxLinksToTheTermsAndThePrivacyPolicy(): void
    {
        foreach (['/register', '/alta/' . $this->reseller->getSignupCode(), '/partners/registro'] as $path) {
            $crawler = $this->client->request('GET', $path);
            $label = $crawler->filter('.accept-terms-label');
            self::assertSame(1, $label->count(), $path);
            self::assertSame(['/terminos', '/privacidad'], $label->filter('a')->each(fn ($a) => $a->attr('href')), $path);
            self::assertSame('He leído y acepto los Términos de Servicio y la Política de Privacidad.', $label->text(), $path);
        }
    }

    private function restaurantForm(string $path, string $email): Form
    {
        $url = $path === 'alta' ? '/alta/' . $this->reseller->getSignupCode() : $path;
        $form = $this->client->request('GET', $url)->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Accept Terms Test ' . uniqid();
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';

        return $form;
    }

    private function assertAccepted(string $email, \DateTimeImmutable $before): void
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertSame(static::getContainer()->get(LegalInfo::class)->termsVersion(), $user->getTermsVersion());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $user->getTermsVersion());
        self::assertNotNull($user->getTermsAcceptedAt());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $user->getTermsAcceptedAt()->getTimestamp());
        self::assertLessThanOrEqual(time() + 1, $user->getTermsAcceptedAt()->getTimestamp());
    }
}
