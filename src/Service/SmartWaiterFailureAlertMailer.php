<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\AI\AIProviderAttempt;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fires when AIModelRouter has exhausted every configured provider for a
 * chat request — i.e. the customer is about to see the generic "I'm having
 * trouble answering, please ask staff" fallback (see
 * _smart_waiter.html.twig's errorFallback). Two separate emails, each
 * throttled so an outage (every message of every restaurant failing) never
 * floods anyone:
 *
 *  - the platform owner: technical detail, so it can actually be fixed —
 *    at most one an hour in total (an outage usually hits every
 *    restaurant at once);
 *  - this restaurant's owners (ROLE_OWNER, not staff): a plain heads-up in
 *    the restaurant's panel language (Restaurant::$adminLocale), so real
 *    staff can step in — at most one a day per restaurant.
 *
 * The "already sent" marks live in the app cache (OWNER_KEY / PLATFORM_KEY,
 * by calendar day / hour), set before sending: a mailer failure doesn't
 * make every next chat message retry it.
 *
 * Sent via the raw mailer TransportInterface, not MailerInterface — this
 * app's messenger.yaml routes Symfony\Component\Mailer\Messenger\
 * SendEmailMessage to the async (Doctrine) transport, and nothing here can
 * guarantee a `messenger:consume` worker is actually running to drain it.
 * An alert whose entire purpose is "something is broken, come look" must
 * not itself depend on a background worker that might be the very thing
 * that's not running — TransportInterface sends immediately, in-request.
 *
 * A mailer failure here (bad credentials, SMTP down) is only ever logged,
 * never allowed to turn an already-failing chat request into a 500 for the
 * customer on top of it.
 */
final class SmartWaiterFailureAlertMailer
{
    private const OWNER_KEY = 'smart_waiter_alert_owner_r%d_%s';
    private const PLATFORM_KEY = 'smart_waiter_alert_platform_%s';

    public function __construct(
        private readonly TransportInterface $mailerTransport,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $platformAdminEmail,
        private readonly TranslatorInterface $translator,
        private readonly AdminLocaleResolver $locales,
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $brandName,
    ) {}

    /** @param AIProviderAttempt[] $attempts every provider tried, in order, all failed */
    public function alert(Restaurant $restaurant, array $attempts): void
    {
        if ($this->fromEmail === '') {
            return; // not configured — see .env's MAILER_FROM_EMAIL
        }

        $now = $this->clock->now();
        if ($this->platformAdminEmail !== '' && $this->firstTime(sprintf(self::PLATFORM_KEY, $now->format('YmdH')), 2 * 3600)) {
            $this->sendPlatformAlert($restaurant, $attempts, $now);
        }
        if ($this->firstTime(sprintf(self::OWNER_KEY, (int) $restaurant->getId(), $now->format('Ymd')), 2 * 86400)) {
            $this->sendOwnerAlert($restaurant);
        }
    }

    /** True the first time a mark is seen (and sets it). */
    private function firstTime(string $key, int $ttl): bool
    {
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            return false;
        }
        $this->cache->save($item->set(true)->expiresAfter($ttl));

        return true;
    }

    /** @param AIProviderAttempt[] $attempts */
    private function sendPlatformAlert(Restaurant $restaurant, array $attempts, \DateTimeImmutable $now): void
    {
        $lines = array_map(
            static fn (AIProviderAttempt $a) => sprintf('- %s: %s — %s', $a->providerId, $a->reason->value, $a->message),
            $attempts
        );
        $attemptsText = $lines === [] ? '(ningún proveedor estaba configurado)' : implode("\n", $lines);
        $timestamp = $now->format('Y-m-d H:i:s');

        $body = <<<TEXT
            El asistente del menú (Smart Waiter) no ha podido responder a un cliente porque todos los proveedores de IA configurados han fallado.

            Restaurante: {$restaurant->getName()} ({$restaurant->getSlug()})
            Fecha: {$timestamp}

            Intentos:
            {$attemptsText}

            Revisa las claves de API y las cuotas de cada proveedor (config/ai_providers.yaml).
            Como mucho recibes un aviso como este por hora, aunque fallen más restaurantes.
            TEXT;

        $this->send(
            to: $this->platformAdminEmail,
            subject: sprintf('⚠️ Smart Waiter caído — %s', $restaurant->getName()),
            body: $body,
        );
    }

    private function sendOwnerAlert(Restaurant $restaurant): void
    {
        $locale = $this->locales->resolve($restaurant->getAdminLocale());
        $t = fn (string $key): string => $this->translator->trans('smart_waiter_down.' . $key, [
            '%restaurant%' => $restaurant->getName(),
            '%app%'        => $this->brandName,
        ], 'emails', $locale);

        $body = implode("\n\n", [$t('hello'), $t('intro'), $t('meanwhile'), $t('at_most'), $t('signature')]);

        foreach ($restaurant->getUsers() as $user) {
            if (in_array(User::ROLE_OWNER, $user->getRoles(), true)) {
                $this->send(to: $user->getEmail(), subject: $t('subject'), body: $body);
            }
        }
    }
    private function send(string $to, string $subject, string $body): void
    {
        $email = (new Email())
            ->from($this->fromEmail)
            ->to($to)
            ->subject($subject)
            ->text($body);

        try {
            $this->mailerTransport->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send Smart Waiter failure alert email', [
                'to' => $to,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
