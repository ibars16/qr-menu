<?php

namespace App\Controller;

use App\Service\Billing\StripeBilling;
use App\Service\Billing\StripeWebhookFailure;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe webhook (signed with STRIPE_WEBHOOK_SECRET). Only the event's type
 * and object id are read here; StripeBilling re-fetches the object itself.
 * Once the signature is valid: 200, and a failure to apply is logged —
 * except a passing one (Stripe or the database down, StripeWebhookFailure),
 * answered 500 so Stripe retries it; re-applying is harmless.
 */
class StripeWebhookController
{
    public function __construct(
        private readonly StripeBilling $billing,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->webhookSecret === '') {
            $this->logger->error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set');
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->headers->get('Stripe-Signature'), $this->webhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $objectId = $event->data->object->id ?? null;
        if (!is_string($objectId)) {
            return new Response();
        }

        try {
            match ($event->type) {
                'checkout.session.completed'    => $this->billing->syncCheckoutSession($objectId),
                'invoice.paid'                  => $this->billing->syncInvoice($objectId),
                'customer.subscription.updated' => $this->billing->syncSubscription($objectId),
                'customer.subscription.deleted' => $this->billing->syncSubscriptionDeleted($objectId),
                default                         => null,
            };
        } catch (\Throwable $e) {
            $this->logger->error('Stripe webhook not applied', ['type' => $event->type, 'object' => $objectId, 'exception' => $e]);

            // Passing failure (Stripe or the database): 500, Stripe sends it again.
            if (StripeWebhookFailure::isTransient($e)) {
                return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new Response();
    }
}
