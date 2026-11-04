<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * /partners "Editar" next to the company name (its name and the contact
 * email its restaurants see, empty = its user's), and "Registrar pago"
 * blocked ("Paga con tarjeta") for a restaurant paying by card, on
 * /partners and on /platform's reseller row.
 */
final class PartnersCompanyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;
    private User $resellerUser;
    private Restaurant $restaurant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->reseller = new Reseller('Partners Company Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);

        $this->resellerUser = $this->user('partners-company-test-reseller', User::ROLE_RESELLER);
        $this->resellerUser->setReseller($this->reseller);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Partners Company Test Restaurant');
        $this->restaurant->setSlug('partners-company-test-' . uniqid());
        $this->restaurant->setReseller($this->reseller);
        $this->em->persist($this->restaurant);

        $this->owner = $this->user('partners-company-test-owner', User::ROLE_OWNER);
        $this->owner->setRestaurant($this->restaurant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'partners-company-test-%\'');
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();
        $conn->executeStatement('DELETE FROM reseller WHERE id = ' . (int) $this->reseller->getId());

        parent::tearDown();
    }

    private function user(string $prefix, string $role): User
    {
        $user = new User();
        $user->setEmail($prefix . '-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([$role]);
        $this->em->persist($user);

        return $user;
    }

    private function panel(): Crawler
    {
        $this->client->loginUser($this->resellerUser);
        $crawler = $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function reloaded(): Reseller
    {
        $this->em->clear();

        return $this->em->find(Reseller::class, $this->reseller->getId());
    }

    private function save(Crawler $crawler, string $name, string $email): void
    {
        $this->client->request('POST', '/partners/empresa', [
            '_token' => $crawler->filter('#company-modal input[name="_token"]')->attr('value'),
            'name'   => $name,
            'email'  => $email,
        ]);
    }

    private function ownerContact(): string
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/billing');
        self::assertResponseIsSuccessful();

        return $crawler->filter('#billing-reseller-email')->attr('href');
    }

    // ── Company details ──

    public function testEditLinkOnlyOnThePanel(): void
    {
        $crawler = $this->panel();
        self::assertSame('Editar', $crawler->filter('#company-edit')->text());
        self::assertSame('Partners Company Test Agency', $crawler->filter('#company-modal input[name="name"]')->attr('value'));
        self::assertSame('', $crawler->filter('#company-modal input[name="email"]')->attr('value'));
        self::assertSame($this->resellerUser->getEmail(), $crawler->filter('#company-modal input[name="email"]')->attr('placeholder'));

        $crawler = $this->client->request('GET', '/partners/billing');
        self::assertCount(0, $crawler->filter('#company-edit'));
    }

    public function testSavesNameAndContactEmailSeenByItsRestaurants(): void
    {
        self::assertSame('mailto:' . $this->resellerUser->getEmail(), $this->ownerContact(), 'empty: its user\'s email');

        $this->save($this->panel(), '  Agencia Nueva  ', 'hola@agencia.example');

        self::assertResponseRedirects('/partners');
        $crawler = $this->client->followRedirect();
        self::assertSame('Agencia Nueva', $crawler->filter('.partners-title')->text());
        self::assertSelectorTextContains('.flash-success', 'Datos de la empresa guardados.');
        self::assertSame('hola@agencia.example', $this->reloaded()->getContactEmail());
        self::assertSame('mailto:hola@agencia.example', $this->ownerContact());
    }

    public function testEmptyContactEmailFallsBackToTheUsers(): void
    {
        $reseller = $this->reloaded();
        $reseller->setContactEmail('old@agencia.example');
        $this->em->flush();

        $this->save($this->panel(), 'Partners Company Test Agency', '');

        self::assertResponseRedirects('/partners');
        self::assertNull($this->reloaded()->getContactEmail());
        self::assertSame('mailto:' . $this->resellerUser->getEmail(), $this->ownerContact());
    }

    public function testInvalidValuesReopenTheDialogAndSaveNothing(): void
    {
        $this->save($this->panel(), 'Partners Company Test Renamed', 'not-an-email');

        self::assertResponseIsUnprocessable();
        $crawler = $this->client->getCrawler();
        self::assertSame('El email de contacto no es válido.', $crawler->filter('#company-modal .form-error')->text());
        self::assertSame('not-an-email', $crawler->filter('#company-modal input[name="email"]')->attr('value'));
        self::assertStringContainsString('CompanyUI.open();', $crawler->filter('body')->html());

        $this->save($crawler, 'X', '');
        self::assertResponseIsUnprocessable();
        self::assertSame('El nombre debe tener entre 2 y 100 caracteres.', $this->client->getCrawler()->filter('#company-modal .form-error')->text());

        $reseller = $this->reloaded();
        self::assertSame('Partners Company Test Agency', $reseller->getName());
        self::assertNull($reseller->getContactEmail());
    }

    public function testEditableWhileExpired(): void
    {
        $this->reloaded()->deactivateNow();
        $this->em->flush();

        $this->save($this->panel(), 'Partners Company Test Agency', 'vencida@agencia.example');

        self::assertResponseRedirects('/partners');
        self::assertSame('vencida@agencia.example', $this->reloaded()->getContactEmail());
    }

    public function testInvalidCsrfChangesNothing(): void
    {
        $this->panel();
        $this->client->request('POST', '/partners/empresa', ['_token' => 'bogus', 'name' => 'Hacked', 'email' => '']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Partners Company Test Agency', $this->reloaded()->getName());
    }

    // ── Back on /partners while "como" an owner ──

    private function enterAsOwner(): void
    {
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    /** Follows the exit: /partners?_switch_user=_exit, then /partners, with the message. */
    private function assertLeftToPartners(): Crawler
    {
        self::assertResponseRedirects('/partners?_switch_user=_exit', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('/partners', parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH));
        self::assertSelectorTextContains('.flash-success', 'Has salido del modo «como dueño» y vuelto a tu panel.');

        return $crawler;
    }

    public function testSavingTheCompanyFromAStalePageLeavesTheOwnerModeInsteadOf403(): void
    {
        // The reseller's /partners page, then "Entrar" and back with the arrow.
        $page = $this->panel();
        $this->enterAsOwner();

        $this->save($page, 'Partners Company Test Stale', 'stale@agencia.example');

        $this->assertLeftToPartners();
        self::assertSame('Partners Company Test Agency', $this->reloaded()->getName(), 'not saved as the owner');

        // Itself again: now it saves.
        $this->save($this->panel(), 'Partners Company Test Stale', '');
        self::assertResponseRedirects('/partners');
        self::assertSame('Partners Company Test Stale', $this->reloaded()->getName());
    }

    public function testOpeningPartnersAsTheOwnerLeavesTheOwnerMode(): void
    {
        $this->panel();
        $this->enterAsOwner();

        $this->client->request('GET', '/partners');

        $crawler = $this->assertLeftToPartners();
        self::assertSame('Partners Company Test Agency', $crawler->filter('.partners-title')->text());
        // Itself again: the owner's panel sends it back to /partners.
        $this->client->request('GET', '/admin/menu');
        self::assertResponseRedirects('/partners');
    }

    public function testPartnersPagesAreNotKeptByTheBrowser(): void
    {
        $this->panel();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testOwnerWithoutImpersonationStillGets403(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/partners');
        self::assertResponseStatusCodeSame(403);
    }

    // ── "Registrar pago" while paying by card ──

    public function testPartnersRegisterPaymentBlockedWhilePayingByCard(): void
    {
        $button = $this->panel()->filter('button.register-payment');
        self::assertSame('Registrar pago', trim($button->text()));
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->em->clear();
        $this->em->find(Restaurant::class, $this->restaurant->getId())->setStripeSubscriptionId('sub_partners_company_card');
        $this->em->flush();

        $blocked = $this->panel()->filter('button.register-payment');
        self::assertSame('Paga con tarjeta', $blocked->text());
        self::assertNotNull($blocked->attr('disabled'));

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'monthly']);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertSame($before, $this->em->find(Restaurant::class, $this->restaurant->getId())->getPaidUntil()->format('Y-m-d'));
    }

    public function testPlatformResellerRowBlockedWhilePayingByCard(): void
    {
        $super = $this->user('partners-company-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();
        $this->client->loginUser($super);
        $action = '/platform/resellers/' . $this->reseller->getId() . '/register-payment';
        $button = $this->client->request('GET', '/platform')->filter('[data-action="' . $action . '"]');
        $before = $this->reseller->getPaidUntil()->format('Y-m-d');

        $reseller = $this->reloaded();
        $reseller->setStripeSubscriptionId('sub_partners_company_reseller');
        $this->em->flush();

        $row = $this->client->request('GET', '/platform')->filter('tr')->reduce(fn (Crawler $tr) => str_contains($tr->text(), 'Partners Company Test Agency') && $tr->filter('.reseller-of')->count() === 0);
        self::assertCount(0, $row->filter('[data-action="' . $action . '"]'));
        self::assertNotNull($row->selectButton('Paga con tarjeta')->attr('disabled'));

        $this->client->request('POST', $action, ['_token' => $button->attr('data-token'), 'plan' => 'monthly']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
    }
}
