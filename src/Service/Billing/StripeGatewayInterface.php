<?php

namespace App\Service\Billing;

/**
 * The few Stripe API calls StripeBilling needs, returning plain arrays (the
 * Stripe object's toArray()) so the billing logic can be tested with a fake
 * and never talks to Stripe in tests.
 */
interface StripeGatewayInterface
{
    /**
     * The same calls made on a connected account (Stripe-Account header):
     * a reseller's account, where its restaurants' customers, Checkout
     * sessions, subscriptions, invoices and portal live. Null = the
     * platform's own account. There, createPortalSession() uses a portal
     * configuration of that account (card and invoices only), created once.
     */
    public function forAccount(?string $accountId): self;

    /**
     * @param array<string, string> $metadata restaurant_id or reseller_id
     * @return string The new customer's id (cus_…).
     */
    public function createCustomer(string $email, string $name, array $metadata): string;

    /**
     * @param array<string, mixed> $params Stripe Checkout Session params
     * @return string The Checkout page URL
     */
    public function createCheckoutSession(array $params): string;

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $id): array;

    /** @return array<string, mixed> */
    public function retrieveInvoice(string $id): array;

    /**
     * @param list<string> $expand e.g. default_payment_method
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $id, array $expand = []): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateSubscription(string $id, array $params): array;

    /** @return array<string, mixed> With invoice_settings.default_payment_method expanded. */
    public function retrieveCustomer(string $id): array;

    /** @return array<string, mixed> */
    public function retrievePrice(string $id): array;

    /** @return list<array<string, mixed>> Newest first. */
    public function listInvoices(string $customerId, int $limit): array;

    /** @return array<string, mixed> */
    public function retrieveSubscriptionSchedule(string $id): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createSubscriptionSchedule(array $params): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateSubscriptionSchedule(string $id, array $params): array;

    /** Detaches the schedule; the subscription carries on as it is now. */
    public function releaseSubscriptionSchedule(string $id): void;

    /**
     * @param array<string, mixed>|null $flowData Stripe's flow_data (deep link), null = the portal's home
     * @return string The Customer Portal URL
     */
    public function createPortalSession(string $customerId, string $returnUrl, ?array $flowData = null): string;

    // ── Stripe Connect (resellers' own accounts) ────────────────────────────

    /**
     * A connected account (Accounts v2, POST /v2/core/accounts).
     *
     * @param array<string, mixed> $params
     * @return string Its id (acct_…).
     */
    public function createConnectedAccount(array $params): string;

    /**
     * @return array<string, mixed> The v2 account with configuration.merchant
     *  and requirements included.
     */
    public function retrieveConnectedAccount(string $accountId): array;

    /** @return string The single-use Stripe-hosted onboarding URL (v2 account link). */
    public function createAccountOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string;

    /**
     * A product in the connected account.
     *
     * @param array<string, mixed> $params
     */
    public function createConnectedProduct(string $accountId, array $params): string;

    /**
     * A price in the connected account.
     *
     * @param array<string, mixed> $params
     */
    public function createConnectedPrice(string $accountId, array $params): string;

    /** No longer offered for new subscriptions; existing ones keep it. */
    public function deactivateConnectedPrice(string $accountId, string $priceId): void;
}
