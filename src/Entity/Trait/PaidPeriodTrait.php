<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * What a paying customer has — a self-billed Restaurant or a Reseller: the
 * paid-until date, the plan, the last payment and the Stripe ids. Same
 * columns in both tables. The using class sets $paidUntil in its
 * constructor (a restaurant's free trial, a reseller's "not paid yet").
 */
trait PaidPeriodTrait
{
    public const PLAN_MONTHLY = 'monthly';
    public const PLAN_YEARLY  = 'yearly';

    /** How far each plan extends paidUntil (keys: PLAN_MONTHLY, PLAN_YEARLY — a trait constant can't refer to self::). */
    public const PLAN_MONTHS = ['monthly' => 1, 'yearly' => 12];

    /**
     * The only thing that decides whether the customer is active: expired
     * = paidUntil before today (so paidUntil itself is still an active day).
     * Afterwards it only moves via markPaid() / deactivateNow() from the
     * platform panel (Platform\PlatformController) and the Stripe sync.
     * "Today" is the PHP default timezone (UTC).
     */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $paidUntil;

    /** Last plan paid for (PLAN_MONTHLY | PLAN_YEARLY); null = never paid (a restaurant's free trial). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $plan = null;

    /** Day the last payment was registered; null = none. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPaidAt = null;

    /** Stripe customer (cus_…), created on the first checkout and reused afterwards. */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $stripeCustomerId = null;

    /**
     * Current Stripe subscription (sub_…) while it's alive; cleared when
     * Stripe deletes it (customer.subscription.deleted). See
     * App\Service\Billing\StripeBilling.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    /**
     * The live subscription won't renew (cancel_at_period_end or a cancel_at
     * date). The dates don't change — the customer simply expires on its
     * own paidUntil.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $stripeCancelPending = false;

    public function getPaidUntil(): \DateTimeImmutable
    {
        return $this->paidUntil;
    }

    public function isExpired(?\DateTimeImmutable $today = null): bool
    {
        return $this->paidUntil < ($today ?? new \DateTimeImmutable('today'));
    }

    /**
     * One more period of $plan (PLAN_MONTHS) from whichever is later,
     * today or the current paidUntil — so paying early never loses days, and
     * paying late never back-dates. Clamped to the target month's last day
     * (31/01 +1 month → 28/02 or 29/02; 29/02 +1 year → 28/02), unlike PHP's
     * own '+1 month' which would overflow to 03/03.
     */
    public function markPaid(string $plan, ?\DateTimeImmutable $today = null): void
    {
        $today = $today ?? new \DateTimeImmutable('today');

        $this->paidUntil  = $this->paidUntilAfter($plan, $today);
        $this->plan       = $plan;
        $this->lastPaidAt = $today;
    }

    public function getPlan(): ?string
    {
        return $this->plan;
    }

    /**
     * A period confirmed by Stripe (App\Service\Billing\StripeBilling). Only
     * ever moves dates forward, so a repeated or out-of-order webhook can't
     * undo a later one. $paidAt is null for a 0 € invoice (the trial's), which
     * must not count as a payment.
     */
    public function applyStripePeriod(\DateTimeImmutable $paidUntil, string $plan, ?\DateTimeImmutable $paidAt): void
    {
        if (!isset(self::PLAN_MONTHS[$plan])) {
            throw new \InvalidArgumentException(sprintf('Unknown plan "%s".', $plan));
        }

        if ($paidUntil > $this->paidUntil) {
            $this->paidUntil = $paidUntil;
        }
        $this->plan = $plan;
        if ($paidAt !== null && ($this->lastPaidAt === null || $paidAt > $this->lastPaidAt)) {
            $this->lastPaidAt = $paidAt;
        }
    }

    public function setPlan(string $plan): void
    {
        if (!isset(self::PLAN_MONTHS[$plan])) {
            throw new \InvalidArgumentException(sprintf('Unknown plan "%s".', $plan));
        }

        $this->plan = $plan;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): void
    {
        $this->stripeCustomerId = $stripeCustomerId;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): void
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
    }

    public function isStripeCancelPending(): bool
    {
        return $this->stripeCancelPending;
    }

    public function setStripeCancelPending(bool $stripeCancelPending): void
    {
        $this->stripeCancelPending = $stripeCancelPending;
    }

    /** Pays automatically through Stripe (subscription not deleted yet, even if set to cancel at period end). */
    public function hasLiveSubscription(): bool
    {
        return $this->stripeSubscriptionId !== null;
    }

    public function getLastPaidAt(): ?\DateTimeImmutable
    {
        return $this->lastPaidAt;
    }

    /** Days from today to paidUntil: 0 = expires today, negative = expired that many days ago. */
    public function getDaysLeft(?\DateTimeImmutable $today = null): int
    {
        $diff = ($today ?? new \DateTimeImmutable('today'))->diff($this->paidUntil);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /** The paidUntil markPaid($plan) would set, without setting it (for /platform's payment dialog). */
    public function paidUntilAfter(string $plan, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        if (!isset(self::PLAN_MONTHS[$plan])) {
            throw new \InvalidArgumentException(sprintf('Unknown plan "%s".', $plan));
        }

        $months = self::PLAN_MONTHS[$plan];
        $today  = $today ?? new \DateTimeImmutable('today');
        $base  = $this->paidUntil > $today ? $this->paidUntil : $today;

        $target = $base->modify('first day of +' . $months . ' months');
        $day    = min((int) $base->format('j'), (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
    }

    public function deactivateNow(?\DateTimeImmutable $today = null): void
    {
        $this->paidUntil = ($today ?? new \DateTimeImmutable('today'))->modify('-1 day');
    }

    /** On the free trial: never paid (plan null) and not expired. */
    public function isTrialActive(?\DateTimeImmutable $today = null): bool
    {
        return $this->plan === null && !$this->isExpired($today);
    }

    public function getTrialDaysRemaining(?\DateTimeImmutable $today = null): int
    {
        if (!$this->isTrialActive($today)) {
            return 0;
        }

        // paidUntil itself is still an active day, so the last day shows "1", not "0".
        return max(1, $this->getDaysLeft($today));
    }

    /**
     * The admin's "vence pronto" notice: a paid plan (never the trial) with 7
     * days or fewer left that won't renew by itself — paid by hand, or a
     * Stripe subscription set to cancel.
     */
    public function isEndingSoon(?\DateTimeImmutable $today = null): bool
    {
        return $this->plan !== null
            && !$this->isExpired($today)
            && $this->getDaysLeft($today) <= 7
            && (!$this->hasLiveSubscription() || $this->stripeCancelPending);
    }
}
