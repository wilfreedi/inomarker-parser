<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\RegexSyncService;
use PHPUnit\Framework\TestCase;

final class RegexSyncServiceTest extends TestCase
{
    private string $targetPath;

    protected function setUp(): void
    {
        $this->targetPath = sys_get_temp_dir() . '/regex-sync-' . bin2hex(random_bytes(8)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->targetPath)) {
            unlink($this->targetPath);
        }
    }

    public function testRefreshDownloadsAndStoresJson(): void
    {
        $requestedUrl = null;
        $service = new RegexSyncService(
            'https://inomarker.ru/api/v1/plugin/regex-data',
            'test-key',
            $this->targetPath,
            static function (string $url) use (&$requestedUrl): array {
                $requestedUrl = $url;

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => json_encode([
                        'foreign_agent' => [
                            'Entity' => [
                                'short' => null,
                                'full' => 'entity-regex',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'error' => null,
                    'curl_failed' => false,
                ];
            }
        );

        $service->refresh();

        self::assertSame(
            'https://inomarker.ru/api/v1/plugin/regex-data?api_key=test-key',
            $requestedUrl
        );
        self::assertFileExists($this->targetPath);

        $decoded = json_decode((string) file_get_contents($this->targetPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('entity-regex', $decoded['foreign_agent']['Entity']['full']);
        self::assertNull($decoded['foreign_agent']['Entity']['short']);
    }

    public function testRefreshFailsOnInvalidPayload(): void
    {
        $service = new RegexSyncService(
            'https://inomarker.ru/api/v1/plugin/regex-data',
            'test-key',
            $this->targetPath,
            static function (string $_url): array {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => json_encode([
                        'foreign_agent' => [
                            'Entity' => [
                                'short' => ['bad'],
                                'full' => 'entity-regex',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'error' => null,
                    'curl_failed' => false,
                ];
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Regex sync payload has invalid short pattern for entity: Entity');
        $service->refresh();
    }
}
