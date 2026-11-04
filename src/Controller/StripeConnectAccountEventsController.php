<?php

namespace App\Controller;

use App\Service\Billing\ResellerConnect;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe event destination for the resellers' connected accounts
 * (Accounts v2 thin events, "Your account" scope, signed with
 * STRIPE_CONNECT_ACCOUNT_EVENTS_SECRET). A thin event carries only its type
 * and the related account's id; ResellerConnect re-reads the account
 * itself, so which v2.core.account* event it is doesn't matter. Charges in
 * those accounts (step 2) arrive at /stripe/connect-webhook instead.
 * Always 200 once the signature is valid — a failure to apply is logged.
 */
class StripeConnectAccountEventsController
{
    public function __construct(
        private readonly ResellerConnect $connect,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/stripe/connect-account-events', name: 'stripe_connect_account_events', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->webhookSecret === '') {
            $this->logger->error('Stripe Connect account event received but STRIPE_CONNECT_ACCOUNT_EVENTS_SECRET is not set');
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = $request->getContent();
        try {
            WebhookSignature::verifyHeader($payload, (string) $request->headers->get('Stripe-Signature'), $this->webhookSecret);
        } catch (SignatureVerificationException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $event = json_decode($payload, true);
        $type = $event['type'] ?? null;
        $related = $event['related_object'] ?? null;
        if (!is_string($type) || !str_starts_with($type, 'v2.core.account')
            || ($related['type'] ?? null) !== 'v2.core.account' || !is_string($related['id'] ?? null)) {
            return new Response();
        }

        try {
            $this->connect->syncAccount($related['id']);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe Connect account event not applied', ['type' => $type, 'account' => $related['id'], 'exception' => $e]);
        }

        return new Response();
    }
}
