<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * /platform: super-admin only; lists every restaurant with its subscription
 * state, and the two CSRF-protected billing actions ("Registrar pago" with
 * a monthly/yearly plan, "Desactivar"), which never delete anything.
 */
final class PlatformControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private User $owner;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Platform Test Restaurant');
        $this->restaurant->setSlug('platform-test-' . uniqid());
        $this->em->persist($this->restaurant);

        $this->owner = new User();
        $this->owner->setEmail('platform-test-owner-' . uniqid() . '@example.test');
        $this->owner->setPassword('unused-in-tests');
        $this->owner->setRoles([User::ROLE_OWNER]);
        $this->owner->setRestaurant($this->restaurant);
        $this->em->persist($this->owner);

        $this->superAdmin = new User();
        $this->superAdmin->setEmail('platform-test-super-' . uniqid() . '@example.test');
        $this->superAdmin->setPassword('unused-in-tests');
        $this->superAdmin->setRoles([User::ROLE_SUPER_ADMIN]);
        $this->em->persist($this->superAdmin);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $this->owner->getId()));
        $this->em->remove($this->em->find(User::class, $this->superAdmin->getId()));
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function reloaded(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    private function setPaidUntil(string $date): void
    {
        // Re-fetched: a client request in between resets the entity manager,
        // leaving $this->restaurant detached.
        $restaurant = $this->reloaded();
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))
            ->setValue($restaurant, new \DateTimeImmutable($date));
        $this->em->flush();
    }

    private function row(): Crawler
    {
        $this->client->loginUser($this->superAdmin);
        $crawler = $this->client->request('GET', '/platform');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('tbody tr')->reduce(fn (Crawler $tr) => str_contains($tr->text(), 'Platform Test Restaurant'));
        self::assertCount(1, $row);

        return $row;
    }

    private function summary(Crawler $crawler, string $label): int
    {
        $card = $crawler->filter('.summary-card')->reduce(fn (Crawler $c) => $c->filter('.summary-label')->text() === $label);

        return (int) $card->filter('.summary-value')->text();
    }

    public function testOwnerCannotOpenPlatform(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/platform');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->client->request('GET', '/platform');

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', $this->client->getResponse()->headers->get('Location'));
    }

    public function testNewRestaurantRowShowsTrial(): void
    {
        $row = $this->row();

        self::assertStringContainsString($this->owner->getEmail(), $row->text());
        self::assertSame('/r/' . $this->restaurant->getSlug(), $row->selectLink('Ver carta')->attr('href'));
        self::assertSame('🟡 Prueba gratis', $row->filter('.status')->text());
        self::assertStringContainsString('Sin cobros', $row->text());
        self::assertStringContainsString($this->restaurant->getPaidUntil()->format('d/m/Y'), $row->filter('.until')->text());
        self::assertSame('quedan 30 días', $row->filter('.until .sub')->text());
        self::assertCount(0, $row->filter('.until-soon, .until-expired'));
    }

    public function testRowWithoutOwnerSaysSo(): void
    {
        $this->owner->setRestaurant(null);
        $this->em->flush();

        self::assertStringContainsString('Sin dueño', $this->row()->filter('.sub')->first()->text());
    }

    public function testPaidRowShowsPlanAndLastPayment(): void
    {
        $this->restaurant->markPaid(Restaurant::PLAN_YEARLY);
        $this->em->flush();

        $row = $this->row();

        self::assertSame('🟢 Activa · Anual', $row->filter('.status')->text());
        self::assertStringContainsString('Último cobro ' . (new \DateTimeImmutable('today'))->format('d/m/Y'), $row->text());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function untilCases(): iterable
    {
        yield 'one day left'     => ['today +1 day', 'queda 1 día', 'until-soon'];
        yield 'seven days left'  => ['today +7 days', 'quedan 7 días', 'until-soon'];
        yield 'expires today'    => ['today', 'vence hoy', 'until-soon'];
        yield 'expired 1 day'    => ['yesterday', 'venció hace 1 día', 'until-expired'];
        yield 'expired 5 days'   => ['today -5 days', 'venció hace 5 días', 'until-expired'];
    }

    #[DataProvider('untilCases')]
    public function testValidUntilText(string $paidUntil, string $text, string $class): void
    {
        $this->setPaidUntil($paidUntil);

        $row = $this->row();

        self::assertSame($text, $row->filter('.until .sub')->text());
        self::assertCount(1, $row->filter('.' . $class));
    }

    public function testAutoPayMarkOnlyWithLiveSubscription(): void
    {
        self::assertCount(0, $this->row()->filter('.auto-pay'));

        $restaurant = $this->reloaded();
        $restaurant->setStripeSubscriptionId('sub_platform_test');
        $this->em->flush();

        self::assertSame('💳 Pago automático', $this->row()->filter('.auto-pay')->text());
    }

    public function testCancelledSubscriptionShowsNoRenewalButSameStatus(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->setStripeSubscriptionId('sub_platform_test');
        $this->em->flush();
        $statusBefore = $this->row()->filter('.status')->text();

        $restaurant = $this->reloaded();
        $restaurant->setStripeCancelPending(true);
        $this->em->flush();

        $row = $this->row();
        self::assertSame('💳 Cancelada · no renovará', $row->filter('.auto-pay')->text());
        self::assertSame($statusBefore, $row->filter('.status')->text());
    }

    public function testCopyPaymentLinkPointsAtOwnersPayRoute(): void
    {
        $button = $this->row()->filter('button.copy-link');

        self::assertSame('Copiar enlace de pago', $button->text());
        self::assertNull($button->attr('disabled'));
        self::assertSame('http://localhost/admin/billing/pay/' . $this->restaurant->getId(), $button->attr('data-url'));
    }

    public function testCopyPaymentLinkDisabledWithoutOwner(): void
    {
        $this->owner->setRestaurant(null);
        $this->em->flush();

        $button = $this->row()->filter('button.copy-link');
        self::assertSame('Sin dueño', $button->text());
        self::assertNotNull($button->attr('disabled'));
        self::assertNull($button->attr('data-url'));
    }

    public function testCopyPaymentLinkHiddenWithAutoPay(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->setStripeSubscriptionId('sub_platform_test');
        $this->em->flush();

        self::assertCount(0, $this->row()->filter('button.copy-link'));
    }

    public function testExpiredRowShowsVencida(): void
    {
        $this->restaurant->deactivateNow();
        $this->em->flush();

        self::assertSame('🔴 Vencida', $this->row()->filter('.status')->text());
    }

    public function testSummaryCountsThisRestaurant(): void
    {
        $this->client->loginUser($this->superAdmin);
        $before = $this->client->request('GET', '/platform');
        $trial = $this->summary($before, 'En prueba');
        $soon = $this->summary($before, 'Vencen en 7 días');
        $expired = $this->summary($before, 'Vencidas');

        $this->setPaidUntil('today +3 days');
        $after = $this->client->request('GET', '/platform');
        self::assertSame($trial, $this->summary($after, 'En prueba'));
        self::assertSame($soon + 1, $this->summary($after, 'Vencen en 7 días'));

        $this->setPaidUntil('yesterday');
        $after = $this->client->request('GET', '/platform');
        self::assertSame($trial - 1, $this->summary($after, 'En prueba'));
        self::assertSame($expired + 1, $this->summary($after, 'Vencidas'));
    }

    public function testListIsOrderedByPaidUntil(): void
    {
        $this->setPaidUntil('today -400 days');

        $this->client->loginUser($this->superAdmin);
        $crawler = $this->client->request('GET', '/platform');

        self::assertSame('Platform Test Restaurant', $crawler->filter('tbody tr .r-name')->first()->text());
    }

    /** @return iterable<string, array{string}> */
    public static function plans(): iterable
    {
        yield 'monthly' => [Restaurant::PLAN_MONTHLY];
        yield 'yearly'  => [Restaurant::PLAN_YEARLY];
    }

    #[DataProvider('plans')]
    public function testRegisterPaymentFromDialogData(string $plan): void
    {
        $button = $this->row()->selectButton('Registrar pago');
        $expected = $this->restaurant->paidUntilAfter($plan);
        self::assertSame($expected->format('d/m/Y'), $button->attr('data-until-' . $plan));

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => $plan]);

        self::assertResponseRedirects('/platform');
        $restaurant = $this->reloaded();
        self::assertSame($expected->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame($plan, $restaurant->getPlan());
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $restaurant->getLastPaidAt()->format('Y-m-d'));
    }

    public function testRegisterPaymentBlockedWhilePayingByCard(): void
    {
        $button = $this->row()->selectButton('Registrar pago');
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');
        $restaurant = $this->reloaded();
        $restaurant->setStripeSubscriptionId('sub_platform_card');
        $this->em->flush();

        $row = $this->row();
        self::assertCount(0, $row->selectButton('Registrar pago'));
        self::assertNotNull($row->selectButton('Paga con tarjeta')->attr('disabled'));

        // Refused server-side too: Stripe adds its days, never twice.
        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'monthly']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
        self::assertNull($this->reloaded()->getLastPaidAt());
    }

    public function testRegisterPaymentRejectsUnknownPlan(): void
    {
        $button = $this->row()->selectButton('Registrar pago');
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'weekly']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
        self::assertNull($this->reloaded()->getPlan());
    }

    public function testRegisterPaymentRejectsInvalidCsrf(): void
    {
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->client->loginUser($this->superAdmin);
        $this->client->request('POST', '/platform/restaurants/' . $this->restaurant->getId() . '/register-payment', ['_token' => 'bogus', 'plan' => 'monthly']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
    }

    public function testDeactivateSetsYesterdayAndKeepsPlan(): void
    {
        $this->restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        $this->em->flush();

        $this->client->submit($this->row()->selectButton('Desactivar')->form());

        self::assertResponseRedirects('/platform');
        $restaurant = $this->reloaded();
        self::assertSame((new \DateTimeImmutable('yesterday'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
    }

    public function testInvalidCsrfOnDeactivateChangesNothing(): void
    {
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->client->loginUser($this->superAdmin);
        $this->client->request('POST', '/platform/restaurants/' . $this->restaurant->getId() . '/deactivate', ['_token' => 'bogus']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
    }

    // ── Back on /platform while "como" an owner ──

    private function enterAsOwner(): void
    {
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    /** Follows the exit: /platform?_switch_user=_exit, then /platform, with the message. */
    private function assertLeftToPlatform(): void
    {
        self::assertResponseRedirects('/platform?_switch_user=_exit', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('/platform', parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH));
        self::assertSelectorTextContains('.flash-success', 'Has salido del modo «como dueño» y vuelto a tu panel.');
    }

    public function testActionFromAStalePageLeavesTheOwnerModeWithoutRunningIt(): void
    {
        // /platform, then "Entrar" as the owner and back with the arrow.
        $button = $this->row()->selectButton('Registrar pago');
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');
        $this->enterAsOwner();

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'yearly']);

        $this->assertLeftToPlatform();
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'), 'not run');
        self::assertNull($this->reloaded()->getPlan());
    }

    public function testOpeningPlatformAsTheOwnerLeavesTheOwnerMode(): void
    {
        $this->row();
        $this->enterAsOwner();

        $this->client->request('GET', '/platform');

        $this->assertLeftToPlatform();
        // Itself again: the admin panel sends it back to /platform.
        $this->client->request('GET', '/admin/menu');
        self::assertResponseRedirects('/platform');
    }

    public function testPlatformPagesAreNotKeptByTheBrowser(): void
    {
        $this->row();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testOwnerCannotPostActions(): void
    {
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->client->loginUser($this->owner);
        $this->client->request('POST', '/platform/restaurants/' . $this->restaurant->getId() . '/register-payment', ['plan' => 'yearly']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
    }
}
