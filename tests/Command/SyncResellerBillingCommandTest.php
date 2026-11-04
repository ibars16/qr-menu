<?php

namespace App\Tests\Command;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:resellers:sync-billing: a reseller's restaurants paying it by card
 * (in its connected account) get their collection paused (void) while it's
 * expired and resumed when it's up to date — a restaurant whose own
 * paid_until passed meanwhile with a new period charged now (anchor now,
 * always_invoice). Repeating changes nothing twice. Resuming also happens
 * at once when the reseller pays: its Stripe invoice or "Registrar pago" on
 * /platform.
 */
final class SyncResellerBillingCommandTest extends WebTestCase
{
    private const ACCOUNT = 'acct_sync_test';
    private const PRICE = 'price_sync_test_monthly';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();
        FakeStripeGateway::price(self::PRICE, 1500, 'month');

        $this->reseller = new Reseller('Sync Billing Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->reseller->setConnectAccountId(self::ACCOUNT);
        $this->reseller->setConnectChargesEnabled(true);
        $this->em->persist($this->reseller);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'sync-billing-test-%\'');
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', 'sync-billing-test-%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Sync Billing Test%'");

        parent::tearDown();
    }

    /** A restaurant of the reseller; with $subscription, paying it by card in its account. */
    private function restaurant(string $name, ?string $subscription, string $paidUntil = 'today +10 days', bool $paused = false): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('sync-billing-test-' . uniqid());
        $restaurant->setReseller($this->em->find(Reseller::class, $this->reseller->getId()));
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable($paidUntil));
        $this->em->persist($restaurant);
        $this->em->flush();

        if ($subscription !== null) {
            $restaurant->setStripeAccountId(self::ACCOUNT);
            $restaurant->setStripeSubscriptionId($subscription);
            $restaurant->setStripeCustomerId('cus_' . $subscription);
            $this->em->flush();
            FakeStripeGateway::subscription($subscription, $restaurant->getId(), self::PRICE, 'cus_' . $subscription);
            if ($paused) {
                FakeStripeGateway::$objects[$subscription]['pause_collection'] = ['behavior' => 'void', 'resumes_at' => null];
            }
        }

        return $restaurant;
    }

    private function expire(): void
    {
        $this->em->clear();
        $this->em->find(Reseller::class, $this->reseller->getId())->deactivateNow();
        $this->em->flush();
    }

    private function runCommand(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:resellers:sync-billing'));
        $tester->execute($options);
        $this->em->clear();

        return $tester;
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> subscription id, params */
    private static function updates(): array
    {
        return array_values(array_map(
            static fn (array $call): array => [$call[1], $call[2]],
            array_filter(FakeStripeGateway::$calls, static fn (array $call): bool => $call[0] === 'updateSubscription'),
        ));
    }

    public function testExpiredResellerPausesItsCardPaymentsOnce(): void
    {
        $this->restaurant('Sync Billing Test Card', 'sub_sync_card');
        $this->restaurant('Sync Billing Test Manual', null);
        $this->expire();

        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        self::assertSame([['sub_sync_card', ['pause_collection' => ['behavior' => 'void']]]], self::updates());
        self::assertContains(['updateSubscription', self::ACCOUNT], FakeStripeGateway::$accountLog, 'in the reseller\'s own account');
        self::assertStringContainsString('Sync Billing Test Agency (vencido) · Sync Billing Test Card: pausado', $tester->getDisplay());

        // Again: already paused, nothing sent.
        $this->runCommand();
        self::assertCount(1, self::updates());
    }

    public function testUpToDateResellerResumesWithoutNewPeriodWhileStillCovered(): void
    {
        $this->restaurant('Sync Billing Test Covered', 'sub_sync_covered', 'today +5 days', paused: true);

        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        self::assertSame([['sub_sync_covered', ['pause_collection' => '']]], self::updates());
        self::assertStringContainsString('(al día) · Sync Billing Test Covered: reanudado', $tester->getDisplay());

        $this->runCommand();
        self::assertCount(1, self::updates(), 'already resumed');
    }

    public function testResumeAfterItsPeriodPassedStartsANewChargedPeriod(): void
    {
        $restaurant = $this->restaurant('Sync Billing Test Lapsed', 'sub_sync_lapsed', 'today -3 days', paused: true);
        $periodEnd = (new \DateTimeImmutable('today +1 month', new \DateTimeZone('UTC')))->getTimestamp();
        FakeStripeGateway::invoice('in_sync_lapsed', 'sub_sync_lapsed', $periodEnd, 1200, time());
        FakeStripeGateway::$objects['sub_sync_lapsed']['latest_invoice'] = 'in_sync_lapsed';

        $this->runCommand()->assertCommandIsSuccessful();

        self::assertSame([['sub_sync_lapsed', [
            'pause_collection'     => '',
            'billing_cycle_anchor' => ['type' => 'now'],
            'proration_behavior'   => 'always_invoice',
        ]]], self::updates());
        // Its invoice applied at once: active again without waiting for the webhook.
        $restaurant = $this->em->find(Restaurant::class, $restaurant->getId());
        self::assertSame((new \DateTimeImmutable('today +1 month'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertFalse($restaurant->isExpired());
    }

    public function testDryRunChangesNothing(): void
    {
        $this->restaurant('Sync Billing Test Dry', 'sub_sync_dry');
        $this->expire();

        $tester = $this->runCommand(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], self::updates());
        self::assertStringContainsString('Sync Billing Test Dry: pausado (simulado)', $tester->getDisplay());
        self::assertNull(FakeStripeGateway::$objects['sub_sync_dry']['pause_collection']);
    }

    public function testEndedSubscriptionIsLeftAlone(): void
    {
        $this->restaurant('Sync Billing Test Ended', 'sub_sync_ended');
        FakeStripeGateway::$objects['sub_sync_ended']['status'] = 'canceled';
        $this->expire();

        $this->runCommand()->assertCommandIsSuccessful();
        self::assertSame([], self::updates());
    }

    public function testStripeFailureIsReportedAndRetriedNextRun(): void
    {
        $this->restaurant('Sync Billing Test Failing', 'sub_sync_failing');
        $this->expire();

        FakeStripeGateway::$fail = true;
        $tester = $this->runCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Sync Billing Test Failing: ERROR', $tester->getDisplay());

        FakeStripeGateway::$fail = false;
        $this->runCommand()->assertCommandIsSuccessful();
        self::assertSame([['sub_sync_failing', ['pause_collection' => ['behavior' => 'void']]]], self::updates());
    }

    public function testResellerWithoutStripeIsSkipped(): void
    {
        $this->em->clear();
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setConnectAccountId(null);
        $reseller->deactivateNow();
        $this->em->flush();

        $this->runCommand()->assertCommandIsSuccessful();
        self::assertSame([], self::updates());
    }

    public function testRegisterPaymentOnPlatformResumesAtOnce(): void
    {
        $this->restaurant('Sync Billing Test Platform', 'sub_sync_platform', paused: true);
        $this->expire();
        $super = new User();
        $super->setEmail('sync-billing-test-super-' . uniqid() . '@example.test');
        $super->setPassword('unused-in-tests');
        $super->setRoles([User::ROLE_SUPER_ADMIN]);
        $this->em->persist($super);
        $this->em->flush();

        $this->client->loginUser($super);
        $crawler = $this->client->request('GET', '/platform');
        $button = $crawler->filter('[data-action="/platform/resellers/' . $this->reseller->getId() . '/register-payment"]');
        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'monthly']);

        self::assertResponseRedirects('/platform');
        self::assertSame([['sub_sync_platform', ['pause_collection' => '']]], self::updates());
    }

    public function testResellerStripePaymentResumesAtOnce(): void
    {
        $this->restaurant('Sync Billing Test Webhook', 'sub_sync_webhook', paused: true);
        $this->expire();
        FakeStripeGateway::resellerSubscription('sub_sync_reseller', $this->reseller->getId(), 'price_test_reseller_monthly', 'price_test_reseller_extra_monthly', 0, 'cus_sync_reseller');
        $periodEnd = (new \DateTimeImmutable('today +1 month', new \DateTimeZone('UTC')))->getTimestamp();
        FakeStripeGateway::invoice('in_sync_reseller', 'sub_sync_reseller', $periodEnd, 4000, time());

        static::getContainer()->get(StripeBilling::class)->syncInvoice('in_sync_reseller');

        $this->em->clear();
        self::assertFalse($this->em->find(Reseller::class, $this->reseller->getId())->isExpired());
        self::assertSame([['sub_sync_webhook', ['pause_collection' => '']]], self::updates());
    }
}
