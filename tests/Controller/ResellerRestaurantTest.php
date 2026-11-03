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
 * A reseller's restaurant: no Stripe billing of its own anywhere (admin
 * panel, /admin/billing, /platform actions), active while its reseller is
 * and its own paid_until (managed in /partners) hasn't passed — the same
 * expired page and unavailable menu as any expired restaurant, without the
 * payment buttons, and "contacta con {revendedor}" instead. Its owner gets
 * a "vence en N días" notice with no payment link. /platform lists the
 * reseller itself with its own manual billing actions.
 */
final class ResellerRestaurantTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;
    private Restaurant $restaurant;
    private User $owner;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->reseller = new Reseller('Reseller Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Reseller Test Restaurant');
        $this->restaurant->setSlug('reseller-test-' . uniqid());
        $this->restaurant->setReseller($this->reseller);
        $this->em->persist($this->restaurant);

        $this->owner = new User();
        $this->owner->setEmail('reseller-test-owner-' . uniqid() . '@example.test');
        $this->owner->setPassword('unused-in-tests');
        $this->owner->setRoles([User::ROLE_OWNER]);
        $this->owner->setRestaurant($this->restaurant);
        $this->em->persist($this->owner);

        $this->superAdmin = new User();
        $this->superAdmin->setEmail('reseller-test-super-' . uniqid() . '@example.test');
        $this->superAdmin->setPassword('unused-in-tests');
        $this->superAdmin->setRoles([User::ROLE_SUPER_ADMIN]);
        $this->em->persist($this->superAdmin);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Restaurant before reseller: the foreign key is RESTRICT.
        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $this->owner->getId()));
        $this->em->remove($this->em->find(User::class, $this->superAdmin->getId()));
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();
        $this->em->remove($this->em->find(Reseller::class, $this->reseller->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function resellerReloaded(): Reseller
    {
        $this->em->clear();

        return $this->em->find(Reseller::class, $this->reseller->getId());
    }

    private function expireReseller(): void
    {
        $this->resellerReloaded()->deactivateNow();
        $this->em->flush();
    }

    private function platformRow(string $name): Crawler
    {
        $this->client->loginUser($this->superAdmin);
        $crawler = $this->client->request('GET', '/platform');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('tbody tr')->reduce(fn (Crawler $tr) => str_contains($tr->filter('.r-name')->count() ? $tr->filter('.r-name')->text() : '', $name));
        self::assertCount(1, $row);

        return $row;
    }

    // ── Admin panel ─────────────────────────────────────────────────────────

    public function testOwnerSeesNoBillingInPanel(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/billing"]');
        self::assertSelectorNotExists('.trial-badge');
        self::assertSelectorNotExists('.billing-strip');
        self::assertSelectorNotExists('.billing-dialog');
    }

    public function testNoEndingSoonNoticeEvenWhenResellerEndsSoon(): void
    {
        $reseller = $this->resellerReloaded();
        (new \ReflectionProperty(Reseller::class, 'paidUntil'))->setValue($reseller, new \DateTimeImmutable('today +2 days'));
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.renewal-badge');
        self::assertSelectorNotExists('.billing-strip');
    }

    private function setOwnPaidUntil(string $date): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurant->getId());
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable($date));
        $this->em->flush();
    }

    public function testOwnerSeesContactNoticeWhenSevenDaysOrLessLeft(): void
    {
        $this->setOwnPaidUntil('today +5 days');

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        $notice = 'Tu servicio vence en 5 días. Contacta con Reseller Test Agency.';
        // Mobile strip: same place and colours as "vence pronto", no link or action.
        self::assertSelectorTextSame('div.billing-strip.is-renewal.reseller-notice .billing-strip-text', $notice);
        self::assertSelectorNotExists('a.billing-strip');
        self::assertSelectorNotExists('.billing-strip-action');
        // Desktop sidebar badge, without "Renovar".
        self::assertSelectorTextSame('.sidebar-header .renewal-badge', $notice);
        self::assertCount(0, $crawler->filter('.sidebar-header .trial-choose'));
        self::assertSelectorNotExists('a[href="/admin/billing"]');
        self::assertSelectorNotExists('.billing-dialog');
    }

    public function testContactNoticeSingularAndToday(): void
    {
        $this->client->loginUser($this->owner);

        $this->setOwnPaidUntil('today +1 day');
        $this->client->request('GET', '/admin/menu');
        self::assertSelectorTextSame('.billing-strip-text', 'Tu servicio vence en 1 día. Contacta con Reseller Test Agency.');

        $this->setOwnPaidUntil('today');
        $this->client->request('GET', '/admin/menu');
        self::assertSelectorTextSame('.billing-strip-text', 'Tu servicio vence hoy. Contacta con Reseller Test Agency.');
    }

    public function testNoContactNoticeWithMoreThanSevenDays(): void
    {
        $this->setOwnPaidUntil('today +8 days');

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.billing-strip');
        self::assertSelectorNotExists('.trial-badge');
    }

    public function testOwnDateExpiredBlocksPanelAndMenuWhileResellerPays(): void
    {
        $this->setOwnPaidUntil('yesterday');

        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es');
        self::assertResponseStatusCodeSame(503);

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('.message', 'Este restaurante no está activo');
        self::assertSelectorTextSame('.hint', 'Contacta con Reseller Test Agency.');
        self::assertSelectorNotExists('form[action="/admin/billing/checkout"]');
        self::assertSelectorNotExists('form[action="/admin/billing/portal"]');
    }

    public function testBillingScreenIsNotFound(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPayLinkGoesToPanel(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/pay/' . $this->restaurant->getId());

        self::assertResponseRedirects('/admin/menu');
    }

    public function testExpiredResellerBlocksPanelWithoutPaymentButtons(): void
    {
        $this->expireReseller();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('.message', 'Este restaurante no está activo');
        self::assertSelectorTextSame('.hint', 'Contacta con Reseller Test Agency.');
        self::assertSelectorNotExists('form[action="/admin/billing/checkout"]');
        self::assertSelectorNotExists('form[action="/admin/billing/portal"]');
        self::assertSelectorExists('a.logout[href="/admin/logout"]');
    }

    public function testExpiredResellerTurnsMenuOff(): void
    {
        $this->expireReseller();

        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es');

        self::assertResponseStatusCodeSame(503);
    }

    public function testArchivedRestaurantIsOffWhileResellerPays(): void
    {
        $this->em->clear();
        $this->em->find(Restaurant::class, $this->restaurant->getId())->archive();
        $this->em->flush();

        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es');
        self::assertResponseStatusCodeSame(503);

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('.message', 'Este restaurante no está activo');
    }

    public function testPaidResellerKeepsMenuOn(): void
    {
        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es');

        self::assertResponseIsSuccessful();
    }

    // ── /platform ───────────────────────────────────────────────────────────

    public function testPlatformRestaurantRowHasNoBillingActions(): void
    {
        $row = $this->platformRow('Reseller Test Restaurant');

        self::assertStringContainsString('Revendedor · Reseller Test Agency', $row->text());
        self::assertStringContainsString('Lo paga el revendedor', $row->text());
        self::assertCount(0, $row->filter('button'));
    }

    public function testPlatformListsResellerWithCountsAndActions(): void
    {
        $row = $this->platformRow('Reseller Test Agency');

        self::assertStringContainsString('1 en uso', $row->filter('.reseller-count')->text());
        self::assertStringContainsString('Activa · Mensual', $row->text());
        self::assertSame('/platform/resellers/' . $this->reseller->getId() . '/register-payment', $row->filter('button.btn-primary')->attr('data-action'));
        self::assertCount(1, $row->filter('form[action="/platform/resellers/' . $this->reseller->getId() . '/deactivate"]'));
    }

    public function testPlatformSummaryLeavesResellerRestaurantOut(): void
    {
        $this->client->loginUser($this->superAdmin);
        $before = $this->client->request('GET', '/platform');
        $total = static fn (Crawler $c): int => array_sum($c->filter('.summary-card .summary-value')->each(fn (Crawler $v) => (int) $v->text()));
        $withIt = $total($before);

        // Detaching it from the reseller makes it count (as a trial).
        $this->em->clear();
        $this->em->find(Restaurant::class, $this->restaurant->getId())->setReseller(null);
        $this->em->flush();

        $after = $this->client->request('GET', '/platform');
        self::assertGreaterThan($withIt, $total($after));
    }

    public function testRegisterResellerPayment(): void
    {
        $this->expireReseller();
        $row = $this->platformRow('Reseller Test Agency');
        $button = $row->filter('button.btn-primary');

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'yearly']);

        self::assertResponseRedirects('/platform');
        $reseller = $this->resellerReloaded();
        self::assertFalse($reseller->isExpired());
        self::assertSame('yearly', $reseller->getPlan());
        self::assertSame($button->attr('data-until-yearly'), $reseller->getPaidUntil()->format('d/m/Y'));
    }

    public function testDeactivateReseller(): void
    {
        $row = $this->platformRow('Reseller Test Agency');
        $form = $row->filter('form[action$="/deactivate"]');

        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseRedirects('/platform');
        self::assertTrue($this->resellerReloaded()->isExpired());
    }

    public function testRestaurantActionsRejectResellerRestaurant(): void
    {
        // Refused before the CSRF check: there's no button for it anyway.
        $this->client->loginUser($this->superAdmin);
        foreach (['deactivate', 'register-payment'] as $action) {
            $this->client->request('POST', '/platform/restaurants/' . $this->restaurant->getId() . '/' . $action, ['_token' => 'x', 'plan' => 'monthly']);
            self::assertResponseStatusCodeSame(400);
        }
        self::assertFalse($this->resellerReloaded()->isExpired());
    }
}
