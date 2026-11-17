<?php

namespace App\Tests\Service\Billing;

use App\Service\Billing\StripeWebhookFailure;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnknownApiErrorException;

/**
 * Which failures make a Stripe webhook answer 500 (Stripe retries) and
 * which keep the 200: App\Service\Billing\StripeWebhookFailure.
 */
final class StripeWebhookFailureTest extends TestCase
{
    /** @return iterable<string, array{\Throwable}> */
    public static function passing(): iterable
    {
        $driver = new class('driver') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };

        yield 'Stripe unreachable' => [ApiConnectionException::factory('down')];
        yield "Stripe's 429" => [RateLimitException::factory('slow down', 429)];
        yield "Stripe's 502" => [UnknownApiErrorException::factory('bad gateway', 502)];
        yield 'database connection lost' => [new ConnectionLost($driver, null)];
        yield 'deadlock' => [new DeadlockException($driver, null)];
        yield 'lock wait timeout' => [new LockWaitTimeoutException($driver, null)];
        yield 'wrapped' => [new \RuntimeException('while syncing', 0, ApiConnectionException::factory('down'))];
    }

    #[DataProvider('passing')]
    public function testPassingFailuresAreRetried(\Throwable $e): void
    {
        self::assertTrue(StripeWebhookFailure::isTransient($e));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function permanent(): iterable
    {
        $driver = new class('driver') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return '23505';
            }
        };

        yield 'object not found' => [InvalidRequestException::factory('No such invoice', 404)];
        yield 'bad API key' => [AuthenticationException::factory('Invalid API key', 401)];
        yield 'unique violation' => [new UniqueConstraintViolationException($driver, null)];
        yield 'a bug' => [new \TypeError('oops')];
        yield 'plain runtime' => [new \RuntimeException('No fake object in_missing')];
    }

    #[DataProvider('permanent')]
    public function testOtherFailuresAreNot(\Throwable $e): void
    {
        self::assertFalse(StripeWebhookFailure::isTransient($e));
    }
}
