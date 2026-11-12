<?php

namespace App\Tests\Service\DishPhoto;

use App\Service\DishPhoto\DishImageFailure;
use App\Service\DishPhoto\DishImageGenerationException;
use App\Service\DishPhoto\GeminiDishImageGenerator;
use App\Service\DishPhoto\GeneratedImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Request shape and response/error reading, against MockHttpClient only — never the network. */
final class GeminiDishImageGeneratorTest extends TestCase
{
    private function generator(MockHttpClient $client, string $key = 'test-key'): GeminiDishImageGenerator
    {
        return new GeminiDishImageGenerator($client, $key, 'gemini-3.1-flash-lite-image');
    }

    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    public function testRequestShapeAndOutputImage(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = [$method, $url, $options];

            return self::json(['output_image' => ['data' => base64_encode('PNGBYTES')]]);
        });

        $image = $this->generator($client)->generate('a prompt');

        self::assertSame('PNGBYTES', $image->bytes);
        [$method, $url, $options] = $seen;
        self::assertSame('POST', $method);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/interactions', $url);
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('x-goog-api-key: test-key', $headers);
        self::assertStringContainsString('Api-Revision: 2026-05-20', $headers);
        self::assertSame([
            'model' => 'gemini-3.1-flash-lite-image',
            'input' => 'a prompt',
            'response_format' => ['type' => 'image', 'aspect_ratio' => '4:3', 'image_size' => '1K'],
        ], json_decode($options['body'], true));
    }

    public function testEditSendsThePhotoNextToTheTextWithItsAspectRatio(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = json_decode($options['body'], true);

            return self::json(['output_image' => ['data' => base64_encode('EDITED')]]);
        });

        $image = $this->generator($client)->edit('retouch it', new GeneratedImage('JPEGBYTES'), 'image/jpeg', '3:4');

        self::assertSame('EDITED', $image->bytes);
        self::assertSame([
            'model' => 'gemini-3.1-flash-lite-image',
            'input' => [
                ['type' => 'text', 'text' => 'retouch it'],
                ['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode('JPEGBYTES')],
            ],
            'response_format' => ['type' => 'image', 'aspect_ratio' => '3:4', 'image_size' => '1K'],
        ], $seen);
    }

    public function testImageInAModelOutputStep(): void
    {
        $client = new MockHttpClient(self::json(['steps' => [
            ['type' => 'thought'],
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'here'], ['type' => 'image', 'data' => base64_encode('JPG')]]],
        ]]));

        self::assertSame('JPG', $this->generator($client)->generate('p')->bytes);
    }

    public static function failures(): array
    {
        return [
            'no image (refusal)' => [self::json(['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'I cannot']]]]]), DishImageFailure::Refused],
            'bad base64' => [self::json(['output_image' => ['data' => '%%%']]), DishImageFailure::Refused],
            '400' => [self::json(['error' => ['message' => 'blocked']], 400), DishImageFailure::Refused],
            '429' => [self::json([], 429), DishImageFailure::Unavailable],
            '503' => [self::json([], 503), DishImageFailure::Unavailable],
            '403 (our key)' => [self::json([], 403), DishImageFailure::NotConfigured],
        ];
    }

    #[DataProvider('failures')]
    public function testFailures(MockResponse $response, DishImageFailure $expected): void
    {
        try {
            $this->generator(new MockHttpClient($response))->generate('p');
            self::fail('expected a failure');
        } catch (DishImageGenerationException $e) {
            self::assertSame($expected, $e->failure);
        }
    }

    public function testNetworkErrorIsUnavailable(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('Connection refused'));

        try {
            $this->generator($client)->generate('p');
            self::fail('expected a failure');
        } catch (DishImageGenerationException $e) {
            self::assertSame(DishImageFailure::Unavailable, $e->failure);
        }
    }

    public function testNoKeyNeverCallsOut(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));

        try {
            $this->generator($client, '  ')->generate('p');
            self::fail('expected a failure');
        } catch (DishImageGenerationException $e) {
            self::assertSame(DishImageFailure::NotConfigured, $e->failure);
        }
    }
}
