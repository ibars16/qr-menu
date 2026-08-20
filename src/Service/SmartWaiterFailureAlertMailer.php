<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Service\AI\AIProviderAttempt;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Fires when AIModelRouter has exhausted every configured provider for a
 * chat request — i.e. the customer is about to see the generic "I'm having
 * trouble answering, please ask staff" fallback (see
 * _smart_waiter.html.twig's errorFallback). Two separate emails: the
 * platform owner (technical detail, so it can actually be fixed) and this
 * restaurant's own account holder(s) (a plain heads-up, so real staff can
 * step in for customers right now).
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
    public function __construct(
        private readonly TransportInterface $mailerTransport,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $platformAdminEmail,
    ) {}

    /** @param AIProviderAttempt[] $attempts every provider tried, in order, all failed */
    public function alert(Restaurant $restaurant, array $attempts): void
    {
        $this->sendOwnerAlert($restaurant, $attempts);
        $this->sendStaffAlert($restaurant);
    }

    /** @param AIProviderAttempt[] $attempts */
    private function sendOwnerAlert(Restaurant $restaurant, array $attempts): void
    {
        if ($this->fromEmail === '' || $this->platformAdminEmail === '') {
            return; // not configured — see .env's MAILER_FROM_EMAIL / PLATFORM_ADMIN_EMAIL
        }

        $lines = array_map(
            static fn (AIProviderAttempt $a) => sprintf('- %s: %s — %s', $a->providerId, $a->reason->value, $a->message),
            $attempts
        );
        $attemptsText = $lines === [] ? '(ningún proveedor estaba configurado)' : implode("\n", $lines);
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $body = <<<TEXT
            El asistente del menú (Smart Waiter) no ha podido responder a un cliente porque todos los proveedores de IA configurados han fallado.

            Restaurante: {$restaurant->getName()} ({$restaurant->getSlug()})
            Fecha: {$timestamp}

            Intentos:
            {$attemptsText}

            Revisa las claves de API y las cuotas de cada proveedor (config/ai_providers.yaml).
            TEXT;

        $this->send(
            to: $this->platformAdminEmail,
            subject: sprintf('⚠️ Smart Waiter caído — %s', $restaurant->getName()),
            body: $body,
        );
    }

    private function sendStaffAlert(Restaurant $restaurant): void
    {
        if ($this->fromEmail === '') {
            return;
        }

        $body = <<<TEXT
            Hola,

            El asistente de IA de vuestra carta digital no está respondiendo en este momento por un problema técnico.

            Mientras se soluciona, os pedimos que atendáis directamente cualquier pregunta que os hagan los clientes sobre el menú, ingredientes, alérgenos o recomendaciones.

            Un saludo,
            El equipo de QR Menu
            TEXT;

        foreach ($restaurant->getUsers() as $user) {
            $this->send(
                to: $user->getEmail(),
                subject: 'El asistente del menú no está disponible ahora mismo',
                body: $body,
            );
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
