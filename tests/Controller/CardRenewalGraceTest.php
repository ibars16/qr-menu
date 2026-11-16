<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Service\Billing\StripeBilling;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A card renewal that fails (Stripe retrying, past_due — FakeStripeGateway,
 * never Stripe): the public menu stays on until paidUntil + 3 days; a
 * payment within those days carries on as usual; without one it's off.
 */
final class CardRenewalGraceTest extends WebTestCase
{
    private const MONTHLY = 'price_test_monthly';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        FakeStripeGateway::reset();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Card Grace Test Restaurant');
        $this->restaurant->setSlug('card-grace-test-' . uniqid());
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        $this->restaurant->setStripeCustomerId('cus_grace_' . uniqid());
        $this->em->persist($this->restaurant);
        $this->em->flush();

        FakeStripeGateway::subscription('sub_grace', $this->restaurant->getId(), self::MONTHLY);
        FakeStripeGateway::$objects['sub_grace']['status'] = 'past_due';
        $this->restaurant->setStripeSubscriptionId('sub_grace');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function paidUntil(string $date): void
    {
        $restaurant = $this->reloaded();
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable($date));
        $this->em->flush();
    }

    private function reloaded(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    private function menuStatus(): int
    {
        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es');

        return $this->client->getResponse()->getStatusCode();
    }

    public function testMenuStaysOnForThreeDaysAfterAFailedRenewal(): void
    {
        foreach (['yesterday', 'today -3 days'] as $date) {
            $this->paidUntil($date);
            self::assertSame(200, $this->menuStatus(), $date);
        }
    }

    public function testFailedInvoiceChangesNothingAndItGoesOffAfterTheGrace(): void
    {
        $this->paidUntil('today -2 days');
        FakeStripeGateway::invoice('in_failed', 'sub_grace', strtotime('+1 month'), 0, time(), 'open');
        static::getContainer()->get(StripeBilling::class)->syncInvoice('in_failed');
        self::assertTrue($this->reloaded()->isInCardRenewalGrace());

        $this->paidUntil('today -4 days');
        self::assertTrue($this->reloaded()->isExpired());
        self::assertSame(503, $this->menuStatus());
    }

    public function testPaymentWithinTheGraceCarriesOn(): void
    {
        $this->paidUntil('today -2 days');
        $periodEnd = (new \DateTimeImmutable('today +1 month', new \DateTimeZone('UTC')))->getTimestamp();
        FakeStripeGateway::invoice('in_retry_paid', 'sub_grace', $periodEnd, 3999, time());

        static::getContainer()->get(StripeBilling::class)->syncInvoice('in_retry_paid');

        $restaurant = $this->reloaded();
        self::assertSame((new \DateTimeImmutable('today +1 month'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertFalse($restaurant->isInCardRenewalGrace());
        self::assertFalse($restaurant->isExpired());
        self::assertSame(200, $this->menuStatus());
    }

    public function testCancelledSubscriptionGetsNoGrace(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->setStripeCancelPending(true);
        $this->em->flush();
        $this->paidUntil('yesterday');

        self::assertSame(503, $this->menuStatus());
    }

    /** Desactivar (platform): the subscription ends first, so no grace. */
    public function testDeactivateNowGetsNoGrace(): void
    {
        $billing = static::getContainer()->get(StripeBilling::class);
        $restaurant = $this->reloaded();
        $billing->cancelNow($restaurant);
        $restaurant->deactivateNow();
        $this->em->flush();

        self::assertSame(503, $this->menuStatus());
    }
}
