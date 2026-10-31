<?php

namespace App\Controller;

use App\Service\Billing\StripeBilling;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe webhook (signed with STRIPE_WEBHOOK_SECRET). Only the event's type
 * and object id are read here; StripeBilling re-fetches the object itself.
 * Always 200 once the signature is valid — a failure to apply is logged,
 * not retried forever by Stripe.
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
        }

        return new Response();
    }
}
