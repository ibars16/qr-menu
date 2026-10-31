<?php

namespace App\Service\Billing;

use Stripe\StripeClient;

/**
 * Real StripeGatewayInterface — the API version is pinned on the
 * StripeClient service (config/services.yaml).
 */
class StripeGateway implements StripeGatewayInterface
{
    public function __construct(private readonly StripeClient $stripe)
    {
    }

    public function createCustomer(string $email, string $name, int $restaurantId): string
    {
        return $this->stripe->customers->create([
            'email'    => $email,
            'name'     => $name,
            'metadata' => ['restaurant_id' => (string) $restaurantId],
        ])->id;
    }

    public function createCheckoutSession(array $params): string
    {
        return $this->stripe->checkout->sessions->create($params)->url;
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->stripe->checkout->sessions->retrieve($id)->toArray();
    }

    public function retrieveInvoice(string $id): array
    {
        return $this->stripe->invoices->retrieve($id)->toArray();
    }

    public function retrieveSubscription(string $id): array
    {
        return $this->stripe->subscriptions->retrieve($id)->toArray();
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        return $this->stripe->billingPortal->sessions->create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ])->url;
    }
}
