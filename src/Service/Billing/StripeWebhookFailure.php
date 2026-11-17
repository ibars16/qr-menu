<?php

namespace App\Service\Billing;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;

/**
 * What the three Stripe webhooks answer when applying an event fails.
 *
 * A passing failure — Stripe unreachable, Stripe's 429 or 5xx, the
 * database down or a deadlock / lock timeout — answers 500, so Stripe
 * sends the event again later (for up to 3 days). Re-applying it is
 * harmless: every sync re-reads the object from Stripe and sets the
 * current state (StripeBilling: paidUntil and lastPaidAt only ever move
 * forward, applyStripePeriod()), so nothing is counted twice.
 *
 * Anything else (an object that doesn't exist, Stripe's other 4xx, a bug)
 * would fail the same way every time: logged and answered 200, as before.
 */
final class StripeWebhookFailure
{
    public static function isTransient(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ApiConnectionException
                || $cause instanceof RateLimitException
                || ($cause instanceof ApiErrorException && $cause->getHttpStatus() >= 500)
                || $cause instanceof ConnectionException
                || $cause instanceof RetryableException) {
                return true;
            }
        }

        return false;
    }
}
