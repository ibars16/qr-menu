<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The account emails: welcome (the three sign-ups only — /register,
 * /alta/{code}, /partners/registro; never staff, restaurants a reseller
 * creates or app:create-admin-user) and "change your password".
 *
 * Sent in the user's panel language: the restaurant's for owners and
 * staff, the reseller's for resellers, Spanish for anyone else (the
 * platform owner).
 *
 * Sent immediately (emails no longer go through the Messenger queue). A
 * failure never breaks the sign-up or the request that triggered it: it is
 * only logged, with the user id and the error type — no address, no text
 * from the mail server (which often quotes the address).
 */
final class AccountMailer
{
    public const APP_NAME = 'QR Menu';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly AdminLocaleResolver $locales,
        private readonly LoggerInterface $logger,
        #[Autowire('%mailer_from_email%')]
        private readonly string $fromEmail,
    ) {}

    public function sendWelcome(User $user): void
    {
        $locale = $this->localeFor($user);
        $this->send($user, 'welcome', $locale, 'emails/welcome.html.twig', [
            'loginUrl' => $this->urls->generate('admin_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'accountEmail' => $user->getEmail(), // not 'email': reserved by TemplatedEmail
        ]);
    }

    public function sendPasswordReset(User $user, string $publicToken): void
    {
        $locale = $this->localeFor($user);
        $this->send($user, 'reset', $locale, 'emails/reset_password.html.twig', [
            'resetUrl' => $this->urls->generate('admin_reset_password_token', ['token' => $publicToken], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    public function localeFor(User $user): string
    {
        if ($restaurant = $user->getRestaurant()) {
            return $this->locales->resolve($restaurant->getAdminLocale());
        }
        if ($reseller = $user->getReseller()) {
            return $this->locales->resolve($reseller->getAdminLocale());
        }

        return 'es';
    }

    /** @param array<string, mixed> $context */
    private function send(User $user, string $kind, string $locale, string $template, array $context): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, self::APP_NAME))
                ->to($user->getEmail())
                ->subject($this->translator->trans($kind . '.subject', ['%app%' => self::APP_NAME], 'emails', $locale))
                // No ->locale(): rendering with it goes through LocaleSwitcher,
                // which afterwards puts the request back on the default locale
                // (en) — the page then came out in English. The templates get
                // the locale explicitly instead.
                ->htmlTemplate($template)
                ->context($context + ['app' => self::APP_NAME, 'locale' => $locale]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Account email not sent', [
                'kind'   => $kind,
                'userId' => $user->getId(),
                'error'  => $e::class,
            ]);
        }
    }
}
