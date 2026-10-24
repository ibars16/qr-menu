<?php

namespace App\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Replaces credentials with [REDACTED] in every log record, on every
 * channel and handler — whatever wrote it (HttpClient's request/response
 * lines, an exception message, our own code).
 *
 * Covers: secret-looking query parameters (?key=…, &api_key=…, access_key,
 * token…), Authorization / x-goog-api-key style headers written inline in a
 * string, and context/extra array entries keyed by one of those names.
 * Defence in depth: no request should carry a key in its URL any more
 * (Gemini takes it in the x-goog-api-key header), but a URL-only API
 * (exchangeratesapi.io's access_key) or a future caller must still never
 * leak one into var/log or prod's stderr.
 */
#[AsMonologProcessor]
final class RedactSecretsProcessor implements ProcessorInterface
{
    public const REDACTED = '[REDACTED]';

    private const SECRET_PARAMS = 'key|api_?key|api-key|access_?key|access_?token|refresh_?token|id_?token|token|auth|client_?secret|secret|password|passwd|signature|sig';

    private const SECRET_HEADERS = 'authorization|proxy-authorization|x-goog-api-key|x-api-key|api-key';

    /** Context/extra keys whose whole value is a secret (compared lowercase). */
    private const SECRET_ARRAY_KEYS = [
        'authorization', 'proxy-authorization', 'x-goog-api-key', 'x-api-key', 'api-key',
        'api_key', 'apikey', 'access_key', 'access_token', 'refresh_token', 'id_token',
        'token', 'client_secret', 'secret', 'password',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    public function redactString(string $value): string
    {
        // ?key=abc / &api_key=abc — stops at the next parameter, fragment,
        // whitespace, quote or backslash (JSON-escaped strings).
        $value = preg_replace(
            '/([?&;](?:' . self::SECRET_PARAMS . ')=)[^&#\s"\'\\\\<>]+/i',
            '$1' . self::REDACTED,
            $value,
        );

        // "Authorization: Bearer abc", "x-goog-api-key: abc" — keeps the
        // auth scheme so the line stays readable.
        return preg_replace_callback(
            '/\b(' . self::SECRET_HEADERS . ')(\s*[:=]\s*)(?:(bearer|basic|token)\s+)?[^\s"\',;\\\\]+/i',
            static fn (array $m): string => $m[1] . $m[2] . (($m[3] ?? '') !== '' ? $m[3] . ' ' : '') . self::REDACTED,
            $value,
        );
    }

    /**
     * @param  array<mixed> $values
     * @return array<mixed>
     */
    private function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_ARRAY_KEYS, true)) {
                $values[$key] = self::REDACTED;
                continue;
            }
            $values[$key] = $this->redactValue($value);
        }

        return $values;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }
        if (is_array($value)) {
            return $this->redactArray($value);
        }
        if ($value instanceof \Throwable) {
            // The formatter would print the exception's own (unredacted)
            // message and trace. Only when it actually carries a secret,
            // swap it for a redacted copy that keeps everything else.
            $text = (string) $value;

            return $this->redactString($text) === $text ? $value : $this->redactThrowable($value);
        }

        return $value;
    }

    /**
     * The exception as plain data — class, message, code, file:line, trace
     * and the previous chain, same fields Monolog's own normalizer writes —
     * with every string redacted.
     *
     * @return array<string, mixed>
     */
    private function redactThrowable(\Throwable $e): array
    {
        $data = [
            'class'   => $e::class,
            'message' => $this->redactString($e->getMessage()),
            'code'    => $e->getCode(),
            'file'    => $this->redactString($e->getFile() . ':' . $e->getLine()),
            'trace'   => $this->redactString($e->getTraceAsString()),
        ];

        if ($e->getPrevious() !== null) {
            $data['previous'] = $this->redactThrowable($e->getPrevious());
        }

        return $data;
    }
}
