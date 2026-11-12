<?php

namespace App\Service\Billing;

use Stripe\StripeClient;

/**
 * Real StripeGatewayInterface — the API version is pinned on the
 * StripeClient service (config/services.yaml). forAccount() gives a copy
 * whose v1 calls carry the connected account (Stripe-Account header).
 */
class StripeGateway implements StripeGatewayInterface
{
    /** Marks the portal configuration this app creates in a connected account. */
    private const PORTAL_CONFIGURATION_TAG = 'qr-menu-restaurant-portal';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly ?string $account = null,
    ) {
    }

    public function forAccount(?string $accountId): self
    {
        return new self($this->stripe, $accountId);
    }

    /** @return array<string, string> */
    private function opts(): array
    {
        return $this->account !== null ? ['stripe_account' => $this->account] : [];
    }

    public function createCustomer(string $email, string $name, array $metadata): string
    {
        return $this->stripe->customers->create([
            'email'    => $email,
            'name'     => $name,
            'metadata' => $metadata,
        ], $this->opts())->id;
    }

    public function createCheckoutSession(array $params): string
    {
        return $this->stripe->checkout->sessions->create($params, $this->opts())->url;
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->stripe->checkout->sessions->retrieve($id, [], $this->opts())->toArray();
    }

    public function retrieveInvoice(string $id): array
    {
        return $this->stripe->invoices->retrieve($id, [], $this->opts())->toArray();
    }

    public function retrieveSubscription(string $id, array $expand = []): array
    {
        return $this->stripe->subscriptions->retrieve($id, $expand !== [] ? ['expand' => $expand] : [], $this->opts())->toArray();
    }

    public function updateSubscription(string $id, array $params): array
    {
        return $this->stripe->subscriptions->update($id, $params, $this->opts())->toArray();
    }

    public function cancelSubscription(string $id): array
    {
        return $this->stripe->subscriptions->cancel($id, [], $this->opts())->toArray();
    }

    public function retrieveCustomer(string $id): array
    {
        return $this->stripe->customers->retrieve($id, ['expand' => ['invoice_settings.default_payment_method']], $this->opts())->toArray();
    }

    public function retrievePrice(string $id): array
    {
        return $this->stripe->prices->retrieve($id, [], $this->opts())->toArray();
    }

    public function listInvoices(string $customerId, int $limit): array
    {
        return $this->stripe->invoices->all(['customer' => $customerId, 'limit' => $limit], $this->opts())->toArray()['data'];
    }

    public function retrieveSubscriptionSchedule(string $id): array
    {
        return $this->stripe->subscriptionSchedules->retrieve($id, [], $this->opts())->toArray();
    }

    public function createSubscriptionSchedule(array $params): array
    {
        return $this->stripe->subscriptionSchedules->create($params, $this->opts())->toArray();
    }

    public function updateSubscriptionSchedule(string $id, array $params): array
    {
        return $this->stripe->subscriptionSchedules->update($id, $params, $this->opts())->toArray();
    }

    public function releaseSubscriptionSchedule(string $id): void
    {
        $this->stripe->subscriptionSchedules->release($id, [], $this->opts());
    }

    public function createPortalSession(string $customerId, string $returnUrl, ?array $flowData = null): string
    {
        return $this->stripe->billingPortal->sessions->create(array_filter([
            'customer'      => $customerId,
            'return_url'    => $returnUrl,
            'flow_data'     => $flowData,
            'configuration' => $this->account !== null ? $this->connectedPortalConfiguration() : null,
        ], static fn ($value): bool => $value !== null), $this->opts())->url;
    }

    /**
     * A connected account has no portal configuration of its own until one
     * is made: this app's (card and invoices only, like the platform's),
     * found by its tag or created once.
     */
    private function connectedPortalConfiguration(): string
    {
        foreach ($this->stripe->billingPortal->configurations->all(['active' => true, 'limit' => 100], $this->opts())->data as $configuration) {
            if (($configuration->metadata['app'] ?? null) === self::PORTAL_CONFIGURATION_TAG) {
                return $configuration->id;
            }
        }

        return $this->stripe->billingPortal->configurations->create([
            'features' => [
                'invoice_history'       => ['enabled' => true],
                'payment_method_update' => ['enabled' => true],
            ],
            'metadata' => ['app' => self::PORTAL_CONFIGURATION_TAG],
        ], $this->opts())->id;
    }

    public function createConnectedAccount(array $params): string
    {
        return $this->stripe->v2->core->accounts->create($params)->id;
    }

    public function retrieveConnectedAccount(string $accountId): array
    {
        return $this->stripe->v2->core->accounts->retrieve($accountId, ['include' => ['configuration.merchant', 'requirements']])->toArray();
    }

    public function createAccountOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return $this->stripe->v2->core->accountLinks->create([
            'account'  => $accountId,
            'use_case' => [
                'type'               => 'account_onboarding',
                'account_onboarding' => ['return_url' => $returnUrl, 'refresh_url' => $refreshUrl],
            ],
        ])->url;
    }

    public function createConnectedProduct(string $accountId, array $params): string
    {
        return $this->stripe->products->create($params, ['stripe_account' => $accountId])->id;
    }

    public function createConnectedPrice(string $accountId, array $params): string
    {
        return $this->stripe->prices->create($params, ['stripe_account' => $accountId])->id;
    }

    public function deactivateConnectedPrice(string $accountId, string $priceId): void
    {
        $this->stripe->prices->update($priceId, ['active' => false], ['stripe_account' => $accountId]);
    }
}
