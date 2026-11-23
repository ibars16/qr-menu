<?php

namespace App\Tests\Command;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Command\SendBillingNoticesCommand;
use App\Service\AccountMailer;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Routing\RequestContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * app:billing:send-notices: "tu prueba gratis acaba mañana" the day before
 * the trial's last day and "tu carta está pausada" once the period ran out —
 * to owners only, in the restaurant's panel language, once per paidUntil.
 */
final class SendBillingNoticesCommandTest extends WebTestCase
{
    private const PREFIX = 'billing-notice-test-';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->executeStatement('DELETE FROM "user" WHERE email LIKE \'' . self::PREFIX . '%\'');
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', self::PREFIX . '%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testTrialEndingTomorrowGoesToOwnersOnlyAndOnce(): void
    {
        $restaurant = $this->restaurant('La Taberna', 'today +1 day');
        $owner = $this->user($restaurant, [User::ROLE_OWNER]);
        $this->user($restaurant, []); // staff

        $this->runNotices();

        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame($owner, $sent[0]->getTo()[0]->getAddress());
        self::assertSame('Tu prueba gratis acaba mañana', $sent[0]->getSubject());
        $html = $sent[0]->getHtmlBody();
        self::assertStringContainsString('La Taberna', $html);
        self::assertStringContainsString('Elegir plan', $html);
        self::assertStringContainsString('href="http://localhost/admin/billing"', $html);

        $this->runNotices();
        self::assertCount(1, self::sent(), 'not sent twice for the same paidUntil');
    }

    public function testNoTrialNoticeWithMoreDaysLeftOrWhenPaid(): void
    {
        $this->user($this->restaurant('Lejos', 'today +3 days'), [User::ROLE_OWNER]);
        $paid = $this->restaurant('Pagado', 'today +1 day');
        $paid->setPlan(Restaurant::PLAN_MONTHLY);
        $this->em->flush();
        $this->user($paid, [User::ROLE_OWNER]);

        $this->runNotices();

        self::assertCount(0, self::sent());
    }

    public function testPausedNoticeInPanelLanguage(): void
    {
        $restaurant = $this->restaurant('Trattoria', 'today -1 day');
        $restaurant->setAdminLocale('it');
        $this->em->flush();
        $owner = $this->user($restaurant, [User::ROLE_OWNER]);

        $this->runNotices();

        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame($owner, $sent[0]->getTo()[0]->getAddress());
        self::assertSame('Il tuo menu è in pausa', $sent[0]->getSubject());
        self::assertStringContainsString('Riattiva il menu', $sent[0]->getHtmlBody());

        $this->runNotices();
        self::assertCount(1, self::sent());
    }

    public function testNoPausedNoticeForLongExpiredOrArchived(): void
    {
        $this->user($this->restaurant('Antiguo', 'today -30 days'), [User::ROLE_OWNER]);
        $archived = $this->restaurant('Archivado', 'today -1 day');
        (new \ReflectionProperty(Restaurant::class, 'archivedAt'))->setValue($archived, new \DateTimeImmutable());
        $this->em->flush();
        $this->user($archived, [User::ROLE_OWNER]);

        $this->runNotices();

        self::assertCount(0, self::sent());
    }

    public function testDryRunSendsNothing(): void
    {
        $this->user($this->restaurant('Simulado', 'today +1 day'), [User::ROLE_OWNER]);

        $tester = $this->runNotices(['--dry-run' => true]);

        self::assertCount(0, self::sent());
        self::assertStringContainsString('Simulado · prueba acaba mañana', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function localHosts(): iterable
    {
        yield 'localhost (también DEFAULT_URI vacía: Symfony usa localhost)' => ['localhost'];
        yield '127.0.0.1' => ['127.0.0.1'];
        yield '::1' => ['[::1]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localHosts')]
    public function testProdRefusesToSendWithLocalDefaultUri(string $host): void
    {
        $this->user($this->restaurant('Sin dirección', 'today +1 day'), [User::ROLE_OWNER]);

        $tester = new CommandTester($this->command('prod', new RequestContext(host: $host)));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ERROR: DEFAULT_URI vacía o localhost', $tester->getDisplay());
        self::assertCount(0, self::sent());
    }

    public function testProdSendsWithPublicDefaultUri(): void
    {
        $this->user($this->restaurant('Con dirección', 'today +1 day'), [User::ROLE_OWNER]);

        $tester = new CommandTester($this->command('prod', new RequestContext(host: 'qrmenu.example', scheme: 'https')));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, self::sent());
    }

    private function command(string $environment, RequestContext $context): SendBillingNoticesCommand
    {
        return new SendBillingNoticesCommand(
            $this->em,
            static::getContainer()->get(AccountMailer::class),
            $context,
            new NullLogger(),
            $environment,
        );
    }

    private function restaurant(string $name, string $paidUntil): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug(self::PREFIX . uniqid());
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable($paidUntil));
        $this->em->persist($restaurant);
        $this->em->flush();

        return $restaurant;
    }

    /** @param list<string> $roles */
    private function user(Restaurant $restaurant, array $roles): string
    {
        $email = self::PREFIX . uniqid() . '@example.test';
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('unused');
        $user->setRoles($roles);
        $user->setRestaurant($restaurant);
        $restaurant->getUsers()->add($user);
        $this->em->persist($user);
        $this->em->flush();

        return $email;
    }

    private function runNotices(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:billing:send-notices'));
        $tester->execute($options);

        return $tester;
    }

    /** Only this test's emails (other restaurants in the test DB may be due too). @return list<Email> */
    private static function sent(): array
    {
        $events = array_filter(self::getMailerEvents(), static fn ($e) => !$e->isQueued());
        $emails = array_map(static fn ($e) => $e->getMessage(), $events);

        return array_values(array_filter($emails, static fn (Email $e) => str_starts_with($e->getTo()[0]->getAddress(), self::PREFIX)));
    }
}
