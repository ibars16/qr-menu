<?php

namespace App\Tests\Service;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\AdminLocaleResolver;
use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderAttempt;
use App\Service\SmartWaiterFailureAlertMailer;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * No DB/kernel needed — SmartWaiterFailureAlertMailer only ever reads
 * Restaurant/User and sends through TransportInterface, which is faked here
 * to capture what would have been sent without touching a real SMTP server.
 * The real translations/emails.*.yaml, a clock the test moves, and an
 * in-memory cache for the "already sent" marks.
 */
final class SmartWaiterFailureAlertMailerTest extends TestCase
{
    private const LOCALES = ['es', 'en', 'de', 'fr', 'it', 'pt'];

    /** @var list<Email> */
    private array $sent = [];
    private MockClock $clock;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-17 10:15:00');
        $this->cache = new ArrayAdapter();
    }

    /** @param array<string, string> $users email => role */
    private function restaurant(array $users, string $adminLocale = 'es', int $id = 7): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Trattoria Roma');
        $restaurant->setSlug('trattoria-roma');
        $restaurant->setAdminLocale($adminLocale);
        (new \ReflectionProperty(Restaurant::class, 'id'))->setValue($restaurant, $id);

        $collection = new ArrayCollection();
        foreach ($users as $email => $role) {
            $user = new User();
            $user->setEmail($email);
            $user->setRoles([$role]);
            $collection->add($user);
        }

        // Restaurant::$users has no addUser() (owning side is User::$restaurant,
        // normally populated by Doctrine on flush) — reflection is the only way
        // to build a populated Restaurant without a real EntityManager here.
        (new \ReflectionProperty(Restaurant::class, 'users'))->setValue($restaurant, $collection);

        return $restaurant;
    }

    /** @return AIProviderAttempt[] */
    private function attempts(): array
    {
        return [
            new AIProviderAttempt('gemini-flash-lite', AIFailureReason::RATE_LIMITED, 'quota exceeded'),
            new AIProviderAttempt('groq-llama', AIFailureReason::TIMEOUT, 'request timed out'),
        ];
    }

    private function mailer(string $from = 'alerts@qrmenu.test', string $platform = 'platform@qrmenu.test', ?TransportInterface $transport = null): SmartWaiterFailureAlertMailer
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (self::LOCALES as $locale) {
            $translator->addResource('yaml', dirname(__DIR__, 2) . "/translations/emails.{$locale}.yaml", $locale, 'emails');
        }

        return new SmartWaiterFailureAlertMailer(
            $transport ?? $this->fakeTransport(fn (Email $email) => $this->sent[] = $email),
            new NullLogger(),
            $from,
            $platform,
            $translator,
            new AdminLocaleResolver(dirname(__DIR__, 2)),
            $this->cache,
            $this->clock,
        );
    }

    /** @return list<string> */
    private function recipients(): array
    {
        return array_map(static fn (Email $e) => $e->getTo()[0]->getAddress(), $this->sent);
    }

    public function testOnlyTheOwnersAndThePlatformAreEmailedNotStaff(): void
    {
        $this->mailer()->alert($this->restaurant([
            'owner1@trattoria.test' => User::ROLE_OWNER,
            'owner2@trattoria.test' => User::ROLE_OWNER,
            'staff@trattoria.test'  => User::ROLE_STAFF,
        ]), $this->attempts());

        self::assertEqualsCanonicalizing(['platform@qrmenu.test', 'owner1@trattoria.test', 'owner2@trattoria.test'], $this->recipients());
        $platform = $this->sent[0];
        self::assertStringContainsString('gemini-flash-lite: rate_limited — quota exceeded', $platform->getTextBody());
    }

    public function testTheOwnerEmailIsInThePanelsLanguage(): void
    {
        $expected = [
            'es' => 'El asistente de tu carta no responde ahora mismo',
            'en' => "Your menu's assistant isn't answering right now",
            'de' => 'Der Assistent Ihrer Karte antwortet gerade nicht',
            'fr' => "L'assistant de votre carte ne répond pas pour le moment",
            'it' => "L'assistente del tuo menù non risponde in questo momento",
            'pt' => 'O assistente da sua ementa não está a responder neste momento',
        ];
        foreach ($expected as $locale => $subject) {
            $this->sent = [];
            $this->mailer(platform: '')->alert($this->restaurant(['owner@trattoria.test' => User::ROLE_OWNER], $locale, id: crc32($locale)), $this->attempts());

            self::assertCount(1, $this->sent, $locale);
            self::assertSame($subject, $this->sent[0]->getSubject(), $locale);
            $body = $this->sent[0]->getTextBody();
            self::assertStringContainsString('Trattoria Roma', $body, $locale);
            self::assertStringContainsString('QR Menu', $body, $locale);
            self::assertStringNotContainsString('smart_waiter_down.', $body, "{$locale}: every key translated");
        }
    }

    public function testAtMostOneOwnerEmailADayPerRestaurant(): void
    {
        $mailer = $this->mailer(platform: '');
        $roma = $this->restaurant(['owner@roma.test' => User::ROLE_OWNER], id: 1);
        $milano = $this->restaurant(['owner@milano.test' => User::ROLE_OWNER], id: 2);

        $mailer->alert($roma, $this->attempts());
        $mailer->alert($roma, $this->attempts());
        $this->clock->modify('+5 hours');
        $mailer->alert($roma, $this->attempts());
        $mailer->alert($milano, $this->attempts()); // another restaurant: its own day
        self::assertSame(['owner@roma.test', 'owner@milano.test'], $this->recipients());

        $this->clock->modify('tomorrow 08:00');
        $mailer->alert($roma, $this->attempts());
        self::assertSame(['owner@roma.test', 'owner@milano.test', 'owner@roma.test'], $this->recipients());
    }

    public function testAtMostOnePlatformEmailAnHourInTotal(): void
    {
        $mailer = $this->mailer();
        $platformEmails = fn (): int => count(array_filter($this->recipients(), static fn (string $to) => $to === 'platform@qrmenu.test'));

        $mailer->alert($this->restaurant([], id: 1), $this->attempts());
        $mailer->alert($this->restaurant([], id: 2), $this->attempts()); // another restaurant, same hour
        $this->clock->modify('+30 minutes');
        $mailer->alert($this->restaurant([], id: 3), $this->attempts());
        self::assertSame(1, $platformEmails());

        $this->clock->modify('+1 hour');
        $mailer->alert($this->restaurant([], id: 1), $this->attempts());
        self::assertSame(2, $platformEmails());
    }

    public function testDoesNothingWhenFromEmailIsNotConfigured(): void
    {
        // Empty fromEmail is the "not configured yet" state this ships with
        // (see .env's MAILER_FROM_EMAIL) — must be a silent no-op, never a
        // crash on top of an already-failing chat request.
        $this->mailer(from: '')->alert($this->restaurant(['owner@trattoria.test' => User::ROLE_OWNER]), $this->attempts());

        self::assertSame([], $this->sent);
    }

    public function testStillEmailsTheOwnerWhenPlatformAdminEmailIsNotConfigured(): void
    {
        $this->mailer(platform: '')->alert($this->restaurant(['owner@trattoria.test' => User::ROLE_OWNER]), $this->attempts());

        self::assertSame(['owner@trattoria.test'], $this->recipients());
    }

    public function testATransportFailureIsSwallowedNotThrown(): void
    {
        $transport = $this->fakeTransport(function () {
            throw new TransportException('SMTP auth failed');
        });

        // Must not throw — an alert that itself crashes would turn a
        // provider outage into a hard failure of the customer's request.
        $this->mailer(transport: $transport)->alert($this->restaurant(['owner@trattoria.test' => User::ROLE_OWNER]), $this->attempts());
        $this->addToAssertionCount(1);
    }

    private function fakeTransport(\Closure $onSend): TransportInterface
    {
        return new class($onSend) implements TransportInterface {
            public function __construct(private readonly \Closure $onSend) {}

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                ($this->onSend)($message);

                return null;
            }

            public function __toString(): string
            {
                return 'fake://transport';
            }
        };
    }
}
