<?php

namespace App\Tests\Service;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderAttempt;
use App\Service\SmartWaiterFailureAlertMailer;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * No DB/kernel needed — SmartWaiterFailureAlertMailer only ever reads
 * Restaurant/User and sends through TransportInterface, which is faked here
 * to capture what would have been sent without touching a real SMTP server.
 */
final class SmartWaiterFailureAlertMailerTest extends TestCase
{
    private function restaurant(array $userEmails): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Trattoria Roma');
        $restaurant->setSlug('trattoria-roma');

        $users = new ArrayCollection();
        foreach ($userEmails as $email) {
            $user = new User();
            $user->setEmail($email);
            $users->add($user);
        }

        // Restaurant::$users has no addUser() (owning side is User::$restaurant,
        // normally populated by Doctrine on flush) — reflection is the only way
        // to build a populated Restaurant without a real EntityManager here.
        $prop = new \ReflectionProperty(Restaurant::class, 'users');
        $prop->setValue($restaurant, $users);

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

    public function testSendsOneOwnerEmailAndOnePerRestaurantUser(): void
    {
        $sent = [];
        $transport = $this->fakeTransport(function (Email $email) use (&$sent) {
            $sent[] = $email->getTo()[0]->getAddress();
        });

        $mailer = new SmartWaiterFailureAlertMailer($transport, new NullLogger(), 'alerts@qrmenu.test', 'owner@qrmenu.test');
        $mailer->alert($this->restaurant(['staff1@trattoria.test', 'staff2@trattoria.test']), $this->attempts());

        $this->assertCount(3, $sent); // 1 owner + 2 staff
        $this->assertContains('owner@qrmenu.test', $sent);
        $this->assertContains('staff1@trattoria.test', $sent);
        $this->assertContains('staff2@trattoria.test', $sent);
    }

    public function testDoesNothingWhenFromEmailIsNotConfigured(): void
    {
        $sendCalled = false;
        $transport = $this->fakeTransport(function () use (&$sendCalled) {
            $sendCalled = true;
        });

        // Empty fromEmail is the "not configured yet" state this ships with
        // (see .env's MAILER_FROM_EMAIL) — must be a silent no-op, never a
        // crash on top of an already-failing chat request.
        $mailer = new SmartWaiterFailureAlertMailer($transport, new NullLogger(), '', 'owner@qrmenu.test');
        $mailer->alert($this->restaurant(['staff@trattoria.test']), $this->attempts());

        $this->assertFalse($sendCalled);
    }

    public function testStillEmailsStaffWhenPlatformAdminEmailIsNotConfigured(): void
    {
        $sent = [];
        $transport = $this->fakeTransport(function (Email $email) use (&$sent) {
            $sent[] = $email->getTo()[0]->getAddress();
        });

        $mailer = new SmartWaiterFailureAlertMailer($transport, new NullLogger(), 'alerts@qrmenu.test', '');
        $mailer->alert($this->restaurant(['staff@trattoria.test']), $this->attempts());

        $this->assertSame(['staff@trattoria.test'], $sent);
    }

    public function testATransportFailureIsSwallowedNotThrown(): void
    {
        $transport = $this->fakeTransport(function () {
            throw new TransportException('SMTP auth failed');
        });

        $mailer = new SmartWaiterFailureAlertMailer($transport, new NullLogger(), 'alerts@qrmenu.test', 'owner@qrmenu.test');

        // Must not throw — an alert that itself crashes would turn a
        // provider outage into a hard failure of the customer's request.
        $mailer->alert($this->restaurant(['staff@trattoria.test']), $this->attempts());
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
