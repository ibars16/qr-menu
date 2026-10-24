<?php

namespace App\Tests\Log;

use App\Log\RedactSecretsProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * No credential reaches a log line, whatever wrote it: query parameters
 * (?key=, api_key, token…), Authorization / x-goog-api-key headers, and
 * context entries keyed by those names all come out as [REDACTED].
 */
final class RedactSecretsProcessorTest extends KernelTestCase
{
    private function logged(string $message, array $context = []): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler], [new RedactSecretsProcessor()]);
        $logger->info($message, $context);

        $record = $handler->getRecords()[0];

        return [$record->message, $record->context];
    }

    public function testAQueryKeyIsRedactedInTheMessageAndTheContext(): void
    {
        [$message, $context] = $this->logged(
            'Response: "200 https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=abc"',
            ['url' => 'https://example.test/v1?alt=json&key=abc&foo=bar', 'http_code' => 200],
        );

        self::assertSame('Response: "200 https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=[REDACTED]"', $message);
        self::assertSame(['url' => 'https://example.test/v1?alt=json&key=[REDACTED]&foo=bar', 'http_code' => 200], $context);
    }

    public function testOtherSecretParametersAreRedactedAndHarmlessOnesKept(): void
    {
        [$message] = $this->logged('GET https://api.test/latest?access_key=s1&api_key=s2&token=s3&page=2&monkey=ok');

        self::assertSame('GET https://api.test/latest?access_key=[REDACTED]&api_key=[REDACTED]&token=[REDACTED]&page=2&monkey=ok', $message);
    }

    public function testInlineHeadersAndSecretContextKeysAreRedacted(): void
    {
        [$message, $context] = $this->logged(
            'Headers: Authorization: Bearer sk-live-abc, x-goog-api-key: abc',
            ['headers' => ['Authorization' => 'Bearer sk-live-abc', 'X-Goog-Api-Key' => ['abc'], 'Content-Type' => 'application/json']],
        );

        self::assertSame('Headers: Authorization: Bearer [REDACTED], x-goog-api-key: [REDACTED]', $message);
        self::assertSame(['headers' => ['Authorization' => '[REDACTED]', 'X-Goog-Api-Key' => '[REDACTED]', 'Content-Type' => 'application/json']], $context);
    }

    private function throwWithKey(): never
    {
        try {
            throw new \LogicException('Bearer token rejected: Authorization: Bearer sk-live-abc');
        } catch (\LogicException $previous) {
            throw new \RuntimeException('Failed for "https://api.test/v1?key=abc"', 42, $previous);
        }
    }

    public function testAnExceptionCarryingAKeyKeepsClassFileLineAndTraceButNotTheKey(): void
    {
        try {
            $this->throwWithKey();
        } catch (\RuntimeException $e) {
        }

        [, $context] = $this->logged('boom', ['exception' => $e]);
        $redacted = $context['exception'];

        self::assertIsArray($redacted);
        self::assertSame(\RuntimeException::class, $redacted['class']);
        self::assertSame('Failed for "https://api.test/v1?key=[REDACTED]"', $redacted['message']);
        self::assertSame(42, $redacted['code']);
        self::assertSame(__FILE__ . ':' . $e->getLine(), $redacted['file']);
        self::assertSame($e->getTraceAsString(), $redacted['trace'], 'the trace carries no secret here, so it is kept verbatim');
        self::assertStringContainsString('throwWithKey', $redacted['trace']);

        self::assertSame(\LogicException::class, $redacted['previous']['class']);
        self::assertSame('Bearer token rejected: Authorization: Bearer [REDACTED]', $redacted['previous']['message']);
        self::assertSame(__FILE__ . ':' . $e->getPrevious()->getLine(), $redacted['previous']['file']);
        self::assertNotSame('', $redacted['previous']['trace']);

        $all = json_encode($redacted);
        self::assertStringNotContainsString('key=abc', $all);
        self::assertStringNotContainsString('sk-live-abc', $all);
    }

    private function fetch(string $url): \RuntimeException
    {
        return new \RuntimeException('upstream failed');
    }

    public function testASecretOnlyInTheTraceIsRedactedAndTheTraceKept(): void
    {
        // Make call arguments show up, untruncated, in the trace text —
        // applied when the trace is rendered, so kept on through the log call.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $maxLen = ini_set('zend.exception_string_param_max_len', '200');
        try {
            $e = $this->fetch('https://api.test/v1?api_key=abc');
            $plain = new \RuntimeException('no secret here');
            self::assertStringContainsString('api_key=abc', $e->getTraceAsString(), 'precondition: the secret is in the trace');

            [, $context] = $this->logged('boom', ['exception' => $e, 'plain' => $plain]);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
            ini_set('zend.exception_string_param_max_len', (string) $maxLen);
        }

        self::assertIsArray($context['exception'], 'a secret anywhere in the exception triggers the redacted copy');
        self::assertSame('upstream failed', $context['exception']['message']);
        self::assertStringContainsString("fetch('https://api.test/v1?api_key=[REDACTED]')", $context['exception']['trace']);
        self::assertStringNotContainsString('api_key=abc', json_encode($context['exception']));
        self::assertSame($plain, $context['plain'], 'an exception without secrets is left untouched for the formatter');
    }

    public function testTheProcessorIsWiredIntoTheAppLogger(): void
    {
        self::bootKernel();
        /** @var Logger $logger */
        $logger = static::getContainer()->get('monolog.logger.http_client');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        try {
            $logger->info('Request: "POST https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=abc"');
        } finally {
            $logger->popHandler();
        }

        self::assertSame('Request: "POST https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=[REDACTED]"', $handler->getRecords()[0]->message);
    }
}
