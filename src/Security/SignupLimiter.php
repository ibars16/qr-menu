<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Sign-ups per IP, one count for the three sign-up forms (/register,
 * /alta/{code}, /partners/registro): 3 an hour and 10 a day
 * (rate_limiter.yaml's signup_ip_*). Only an account actually created
 * counts — a form sent back with errors doesn't. Past either, the form
 * shows its "too many sign-ups" error and nothing is created. No captcha,
 * no new screen. Off when app.abuse_limits is (dev).
 */
final class SignupLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.signup_ip_hour')] private readonly RateLimiterFactoryInterface $hourLimiter,
        #[Autowire(service: 'limiter.signup_ip_day')] private readonly RateLimiterFactoryInterface $dayLimiter,
        #[Autowire('%app.abuse_limits%')] private readonly bool $enabled,
    ) {
    }

    /** May this request's IP create one more account now? Counts nothing. */
    public function allows(Request $request): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $ip = self::key($request);

        return $this->hourLimiter->create($ip)->consume(0)->getRemainingTokens() > 0
            && $this->dayLimiter->create($ip)->consume(0)->getRemainingTokens() > 0;
    }

    /** An account was just created from this request's IP. */
    public function count(Request $request): void
    {
        if (!$this->enabled) {
            return;
        }
        $ip = self::key($request);
        $this->hourLimiter->create($ip)->consume();
        $this->dayLimiter->create($ip)->consume();
    }

    private static function key(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
