<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\CrawlerClient;
use PHPUnit\Framework\TestCase;

final class CrawlerClientTest extends TestCase
{
    public function testReturnsPagesOnSuccessfulFirstAttempt(): void
    {
        $client = new CrawlerClient(
            'http://crawler.local/crawl',
            static function (): array {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => json_encode(['pages' => [['url' => 'https://example.org', 'text' => 'hello']]], JSON_THROW_ON_ERROR),
                    'error' => null,
                    'curl_failed' => false,
                ];
            }
        );

        $pages = $client->crawl('https://example.org', ['retry_attempts' => 2, 'retry_delay_ms' => 1]);
        self::assertCount(1, $pages);
        self::assertSame('https://example.org', $pages[0]['url']);
    }

    public function testRetriesOnRetryableStatusAndThenSucceeds(): void
    {
        $attempt = 0;
        $client = new CrawlerClient(
            'http://crawler.local/crawl',
            static function () use (&$attempt): array {
                $attempt++;
                if ($attempt === 1) {
                    return [
                        'ok' => false,
                        'status' => 503,
                        'body' => '{"error":"temporary"}',
                        'error' => 'Crawler error [503]: {"error":"temporary"}',
                        'curl_failed' => false,
                    ];
                }

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => json_encode(['pages' => [['url' => 'https://example.org/retry', 'text' => 'retry-ok']]], JSON_THROW_ON_ERROR),
                    'error' => null,
                    'curl_failed' => false,
                ];
            }
        );

        $pages = $client->crawl('https://example.org', ['retry_attempts' => 2, 'retry_delay_ms' => 1]);
        self::assertCount(1, $pages);
        self::assertSame(2, $attempt);
        self::assertSame('https://example.org/retry', $pages[0]['url']);
    }

    public function testDoesNotRetryOnNonRetryableClientError(): void
    {
        $attempt = 0;
        $client = new CrawlerClient(
            'http://crawler.local/crawl',
            static function () use (&$attempt): array {
                $attempt++;
                return [
                    'ok' => false,
                    'status' => 400,
                    'body' => '{"error":"bad request"}',
                    'error' => 'Crawler error [400]: {"error":"bad request"}',
                    'curl_failed' => false,
                ];
            }
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Crawler failed after 3 attempts: Crawler error [400]: {"error":"bad request"}');
        try {
            $client->crawl('https://example.org', ['retry_attempts' => 3, 'retry_delay_ms' => 1]);
        } finally {
            self::assertSame(1, $attempt);
        }
    }

    public function testFailsAfterExhaustingRetries(): void
    {
        $attempt = 0;
        $client = new CrawlerClient(
            'http://crawler.local/crawl',
            static function () use (&$attempt): array {
                $attempt++;
                return [
                    'ok' => false,
                    'status' => 503,
                    'body' => '{"error":"down"}',
                    'error' => 'Crawler error [503]: {"error":"down"}',
                    'curl_failed' => false,
                ];
            }
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Crawler failed after 2 attempts: Crawler error [503]: {"error":"down"}');
        try {
            $client->crawl('https://example.org', ['retry_attempts' => 2, 'retry_delay_ms' => 1]);
        } finally {
            self::assertSame(2, $attempt);
        }
    }

    public function testIncludesConfiguredPagePauseInPayload(): void
    {
        $capturedPayload = null;
        $client = new CrawlerClient(
            'http://crawler.local/crawl',
            static function (string $_endpoint, string $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => json_encode(['pages' => [['url' => 'https://example.org/pause', 'text' => 'ok']]], JSON_THROW_ON_ERROR),
                    'error' => null,
                    'curl_failed' => false,
                ];
            }
        );

        $client->crawl('https://example.org', [
            'max_pages' => 222,
            'max_depth' => 7,
            'timeout_ms' => 44000,
            'page_pause_ms' => 1000,
            'request_timeout_seconds' => 120,
            'retry_attempts' => 1,
            'retry_delay_ms' => 1,
        ]);

        self::assertIsString($capturedPayload);
        $decoded = json_decode((string) $capturedPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(222, $decoded['maxPages']);
        self::assertSame(7, $decoded['maxDepth']);
        self::assertSame(44000, $decoded['timeoutMs']);
        self::assertSame(1000, $decoded['pagePauseMs']);
        self::assertSame(115000, $decoded['maxDurationMs']);
    }

    public function testIncludesProgressCallbackWhenConfigured(): void
    {
        $capturedPayload = null;
        $client = new CrawlerClient(
            'http://crawler.local/crawl',
            static function (string $_endpoint, string $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => json_encode(['pages' => [['url' => 'https://example.org/progress', 'text' => 'ok']]], JSON_THROW_ON_ERROR),
                    'error' => null,
                    'curl_failed' => false,
                ];
            },
            'http://app.local/internal/crawl-progress',
            'secret-token'
        );

        $client->crawl('https://example.org', [
            'max_pages' => 10,
            'max_depth' => 2,
            'timeout_ms' => 15000,
            'page_pause_ms' => 100,
            'request_timeout_seconds' => 60,
            'retry_attempts' => 1,
            'retry_delay_ms' => 1,
            'site_id' => 7,
            'run_id' => 99,
        ]);

        self::assertIsString($capturedPayload);
        $decoded = json_decode((string) $capturedPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded['progressCallback']);
        self::assertSame('http://app.local/internal/crawl-progress', $decoded['progressCallback']['url']);
        self::assertSame('secret-token', $decoded['progressCallback']['token']);
        self::assertSame(7, $decoded['progressCallback']['siteId']);
        self::assertSame(99, $decoded['progressCallback']['runId']);
    }
}
