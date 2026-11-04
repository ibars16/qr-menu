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
 * Stripe webhook for the resellers' connected accounts ("Connected
 * accounts" scope, snapshot events, signed with STRIPE_CONNECT_WEBHOOK_SECRET):
 * their restaurants' Checkout sessions, paid invoices and subscriptions.
 * The same handling as StripeWebhookController, but in the event's account
 * — StripeBilling re-reads each object there and only applies it to a
 * restaurant of the reseller owning that account. The accounts' own
 * changes arrive at /stripe/connect-account-events instead.
 * Always 200 once the signature is valid — a failure to apply is logged.
 */
class StripeConnectWebhookController
{
    public function __construct(
        private readonly StripeBilling $billing,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/stripe/connect-webhook', name: 'stripe_connect_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->webhookSecret === '') {
            $this->logger->error('Stripe Connect webhook received but STRIPE_CONNECT_WEBHOOK_SECRET is not set');
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->headers->get('Stripe-Signature'), $this->webhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $account = $event->account ?? null;
        $objectId = $event->data->object->id ?? null;
        if (!is_string($account) || !is_string($objectId)) {
            return new Response();
        }

        try {
            match ($event->type) {
                'checkout.session.completed'    => $this->billing->syncCheckoutSession($objectId, null, $account),
                'invoice.paid'                  => $this->billing->syncInvoice($objectId, $account),
                'customer.subscription.updated' => $this->billing->syncSubscription($objectId, $account),
                'customer.subscription.deleted' => $this->billing->syncSubscriptionDeleted($objectId, $account),
                default                         => null,
            };
        } catch (\Throwable $e) {
            $this->logger->error('Stripe Connect webhook not applied', ['type' => $event->type, 'account' => $account, 'object' => $objectId, 'exception' => $e]);
        }

        return new Response();
    }
}
