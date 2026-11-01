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

    public function retrieveSubscription(string $id, array $expand = []): array
    {
        return $this->stripe->subscriptions->retrieve($id, $expand !== [] ? ['expand' => $expand] : [])->toArray();
    }

    public function updateSubscription(string $id, array $params): array
    {
        return $this->stripe->subscriptions->update($id, $params)->toArray();
    }

    public function retrieveCustomer(string $id): array
    {
        return $this->stripe->customers->retrieve($id, ['expand' => ['invoice_settings.default_payment_method']])->toArray();
    }

    public function retrievePrice(string $id): array
    {
        return $this->stripe->prices->retrieve($id)->toArray();
    }

    public function listInvoices(string $customerId, int $limit): array
    {
        return $this->stripe->invoices->all(['customer' => $customerId, 'limit' => $limit])->toArray()['data'];
    }

    public function retrieveSubscriptionSchedule(string $id): array
    {
        return $this->stripe->subscriptionSchedules->retrieve($id)->toArray();
    }

    public function createSubscriptionSchedule(array $params): array
    {
        return $this->stripe->subscriptionSchedules->create($params)->toArray();
    }

    public function updateSubscriptionSchedule(string $id, array $params): array
    {
        return $this->stripe->subscriptionSchedules->update($id, $params)->toArray();
    }

    public function releaseSubscriptionSchedule(string $id): void
    {
        $this->stripe->subscriptionSchedules->release($id);
    }

    public function createPortalSession(string $customerId, string $returnUrl, ?array $flowData = null): string
    {
        return $this->stripe->billingPortal->sessions->create(array_filter([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
            'flow_data'  => $flowData,
        ], static fn ($value): bool => $value !== null))->url;
    }
}
