<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /stripe/webhook: only signed requests (STRIPE_WEBHOOK_SECRET, fake in
 * .env.test) are applied; the object itself comes from FakeStripeGateway.
 */
final class StripeWebhookControllerTest extends WebTestCase
{
    private const SECRET = 'whsec_fake_for_tests';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Stripe Webhook Test Restaurant');
        $this->restaurant->setSlug('stripe-webhook-test-' . uniqid());
        $this->em->persist($this->restaurant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function post(string $type, string $objectId, ?string $secret = self::SECRET): void
    {
        $payload = json_encode([
            'id' => 'evt_' . uniqid(), 'object' => 'event', 'type' => $type,
            'data' => ['object' => ['id' => $objectId]],
        ]);
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret);

        $this->client->request('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);
    }

    private function reloaded(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    public function testBadSignatureIsRejectedAndChangesNothing(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), 'price_test_monthly');
        FakeStripeGateway::invoice('in_1', 'sub_1', strtotime('+40 days'), 3999, time());

        $this->post('invoice.paid', 'in_1', 'whsec_wrong');

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->reloaded()->getPlan());
    }

    public function testPaidInvoiceIsApplied(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), 'price_test_monthly');
        FakeStripeGateway::invoice('in_1', 'sub_1', strtotime('+40 days'), 3999, time());

        $this->post('invoice.paid', 'in_1');

        self::assertResponseIsSuccessful();
        $restaurant = $this->reloaded();
        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
        self::assertSame(gmdate('Y-m-d', strtotime('+40 days')), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('sub_1', $restaurant->getStripeSubscriptionId());
    }

    /**
     * A subscription still on a price that's no longer STRIPE_PRICE_* (our
     * prices changed; Stripe keeps it on the old one): its plan comes from
     * the price's interval, and the paid invoice still extends paidUntil.
     */
    public function testPaidInvoiceOnOldPriceIsApplied(): void
    {
        FakeStripeGateway::price('price_old_monthly', 3000, 'month');
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), 'price_old_monthly');
        FakeStripeGateway::invoice('in_1', 'sub_1', strtotime('+40 days'), 3000, time());

        $this->post('invoice.paid', 'in_1');

        self::assertResponseIsSuccessful();
        $restaurant = $this->reloaded();
        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
        self::assertSame(gmdate('Y-m-d', strtotime('+40 days')), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('sub_1', $restaurant->getStripeSubscriptionId());
    }

    public function testPaidInvoiceOnOldYearlyPriceIsApplied(): void
    {
        FakeStripeGateway::price('price_old_yearly', 30000, 'year');
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), 'price_old_yearly');
        FakeStripeGateway::invoice('in_1', 'sub_1', strtotime('+400 days'), 30000, time());

        $this->post('invoice.paid', 'in_1');

        self::assertResponseIsSuccessful();
        $restaurant = $this->reloaded();
        self::assertSame(Restaurant::PLAN_YEARLY, $restaurant->getPlan());
        self::assertSame(gmdate('Y-m-d', strtotime('+400 days')), $restaurant->getPaidUntil()->format('Y-m-d'));
    }

    public function testRepeatedEventIsHarmless(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), 'price_test_yearly');
        FakeStripeGateway::invoice('in_1', 'sub_1', strtotime('+400 days'), 39999, time());

        $this->post('invoice.paid', 'in_1');
        $this->post('invoice.paid', 'in_1');

        self::assertResponseIsSuccessful();
        self::assertSame(gmdate('Y-m-d', strtotime('+400 days')), $this->reloaded()->getPaidUntil()->format('Y-m-d'));
    }

    public function testCancelledSubscriptionOnlyUnlinks(): void
    {
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->post('customer.subscription.deleted', 'sub_1');

        self::assertResponseIsSuccessful();
        $restaurant = $this->reloaded();
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertSame($before, $restaurant->getPaidUntil()->format('Y-m-d'));
    }

    public function testSubscriptionUpdatedMarksCancel(): void
    {
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), 'price_test_monthly');
        FakeStripeGateway::$objects['sub_1']['cancel_at_period_end'] = true;

        $this->post('customer.subscription.updated', 'sub_1');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->reloaded()->isStripeCancelPending());
    }

    public function testFailedPaymentAndUnknownEventsChangeNothing(): void
    {
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->post('invoice.payment_failed', 'in_x');
        self::assertResponseIsSuccessful();
        $this->post('customer.created', 'cus_x');
        self::assertResponseIsSuccessful();

        self::assertSame($before, $this->reloaded()->getPaidUntil()->format('Y-m-d'));
    }

    public function testApplyErrorStillAnswers200(): void
    {
        // Unknown invoice id: the fake throws, like a Stripe API error would.
        $this->post('invoice.paid', 'in_missing');

        self::assertResponseIsSuccessful();
    }
}
