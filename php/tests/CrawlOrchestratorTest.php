<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\CrawlRunRepository;
use App\Repository\FindingRepository;
use App\Repository\PageRepository;
use App\Repository\RunRepository;
use App\Repository\SiteRepository;
use App\Service\CrawlOrchestrator;
use App\Service\CrawledPageProcessor;
use App\Service\CrawlerClient;
use App\Service\PatternCatalog;
use App\Tests\Support\DatabaseTestCase;

final class CrawlOrchestratorTest extends DatabaseTestCase
{
    private string $patternsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = tempnam(sys_get_temp_dir(), 'parser-patterns-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create patterns fixture');
        }
        $this->patternsPath = $tmp;

        file_put_contents($this->patternsPath, json_encode([
            'foreign_agent' => [
                'Entity Alpha' => [
                    'short' => 'alpha',
                    'full' => 'entity alpha',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (isset($this->patternsPath) && file_exists($this->patternsPath)) {
            unlink($this->patternsPath);
        }

        parent::tearDown();
    }

    public function testScanCompletesAndPersistsPagesAndFindings(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $orchestrator = $this->buildOrchestrator(static function (): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'pages' => [
                        ['url' => 'https://example.org', 'status' => 200, 'title' => 'Home', 'text' => 'Entity Alpha appears here'],
                    ],
                    'stats' => ['returned' => 1],
                ], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        });

        $result = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $result['pages_total']);
        self::assertSame(1, $result['pages_with_matches']);

        $runs = (new RunRepository($this->pdo))->recentBySite($siteId, 5);
        self::assertCount(1, $runs);
        self::assertSame('completed', $runs[0]['status']);

        $storedSite = $siteRepository->findById($siteId);
        self::assertSame('idle', $storedSite['status']);
        self::assertNull($storedSite['last_error']);

        $findings = (new FindingRepository($this->pdo))->recentBySite($siteId, 10);
        self::assertCount(2, $findings);
        self::assertSame('Entity Alpha', $findings[0]['entity_name']);
        $sources = array_values(array_unique(array_map(static fn (array $finding): string => (string) $finding['pattern_source'], $findings)));
        sort($sources);
        self::assertSame(['full', 'short'], $sources);
    }

    public function testScanFailsWhenCrawlerReturnsNoPages(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $orchestrator = $this->buildOrchestrator(static function (): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode(['pages' => [], 'stats' => ['returned' => 0]], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        });

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Crawler returned no pages');
        try {
            $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        } finally {
            $runs = (new RunRepository($this->pdo))->recentBySite($siteId, 5);
            self::assertSame('failed', $runs[0]['status']);
            self::assertStringContainsString('Crawler returned no pages', (string) $runs[0]['error_message']);

            $storedSite = $siteRepository->findById($siteId);
            self::assertSame('paused', $storedSite['status']);
            self::assertSame(0, (int) $storedSite['is_enabled']);
        }
    }

    public function testScanStoresFullAndShortFindingsInSingleRun(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $orchestrator = $this->buildOrchestrator(static function (): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'pages' => [
                        ['url' => 'https://example.org', 'status' => 200, 'title' => 'Home', 'text' => 'entity alpha appears here, alpha appears too'],
                    ],
                    'stats' => ['returned' => 1],
                ], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        });

        $result = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $result['pages_total']);
        self::assertSame(1, $result['pages_with_matches']);

        $findings = (new FindingRepository($this->pdo))->recentBySite($siteId, 10);
        self::assertCount(2, $findings);
        $sources = array_values(array_unique(array_map(static fn (array $finding): string => (string) $finding['pattern_source'], $findings)));
        sort($sources);
        self::assertSame(['full', 'short'], $sources);
    }

    public function testScanFailsWhenCrawlerReturnsOnlyInvalidUrls(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $orchestrator = $this->buildOrchestrator(static function (): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'pages' => [
                        ['url' => '', 'status' => 200, 'title' => 'Broken', 'text' => 'Entity Alpha'],
                        ['url' => 'not-a-url', 'status' => 200, 'title' => 'Broken 2', 'text' => 'Entity Alpha'],
                    ],
                    'stats' => ['returned' => 2],
                ], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        });

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Crawler returned no valid pages');
        try {
            $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        } finally {
            $runs = (new RunRepository($this->pdo))->recentBySite($siteId, 5);
            self::assertSame('failed', $runs[0]['status']);
            self::assertStringContainsString('Crawler returned no valid pages', (string) $runs[0]['error_message']);
        }
    }

    public function testScanSkipsAlreadyMatchedPageAndDoesNotDuplicateFindings(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $findingRepository = new FindingRepository($this->pdo);

        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $orchestrator = $this->buildOrchestrator(static function (): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'pages' => [
                        ['url' => 'https://example.org', 'status' => 200, 'title' => 'Home', 'text' => 'Entity Alpha appears here'],
                    ],
                    'stats' => ['returned' => 1],
                ], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        });

        $first = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $first['pages_with_matches']);

        $storedPage = $pageRepository->findBySiteAndUrl($siteId, 'https://example.org');
        self::assertNotNull($storedPage);
        self::assertSame(1, (int) $storedPage['is_matched']);

        $findingsBefore = $findingRepository->recentBySite($siteId, 10);
        self::assertCount(2, $findingsBefore);

        $second = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $second['pages_total']);
        self::assertSame(0, $second['pages_with_matches']);

        $findingsAfter = $findingRepository->recentBySite($siteId, 10);
        self::assertCount(2, $findingsAfter);
    }

    public function testScanSkipsRegexForUnchangedPageWithoutFindings(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $findingRepository = new FindingRepository($this->pdo);

        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $orchestrator = $this->buildOrchestrator(static function (): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'pages' => [
                        ['url' => 'https://example.org/no-match', 'status' => 200, 'title' => 'No Match', 'text' => 'regular page body'],
                    ],
                    'stats' => ['returned' => 1],
                ], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        });

        $first = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $first['pages_total']);
        self::assertSame(0, $first['pages_with_matches']);
        self::assertCount(0, $findingRepository->recentBySite($siteId, 10));

        $storedPageBefore = $pageRepository->findBySiteAndUrl($siteId, 'https://example.org/no-match');
        self::assertNotNull($storedPageBefore);
        $firstCrawledAt = (string) ($storedPageBefore['crawled_at'] ?? '');

        usleep(1_100_000);

        $second = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $second['pages_total']);
        self::assertSame(0, $second['pages_with_matches']);
        self::assertCount(0, $findingRepository->recentBySite($siteId, 10));

        $storedPageAfter = $pageRepository->findBySiteAndUrl($siteId, 'https://example.org/no-match');
        self::assertNotNull($storedPageAfter);
        self::assertNotSame($firstCrawledAt, (string) ($storedPageAfter['crawled_at'] ?? ''));
    }

    public function testScanCompletesWhenPagesAreStreamedIncrementally(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $pageProcessor = new CrawledPageProcessor(
            new PageRepository($this->pdo),
            new FindingRepository($this->pdo),
            new PatternCatalog($this->patternsPath)
        );
        $orchestrator = $this->buildOrchestrator(static function (string $_endpoint, string $payload) use ($pageProcessor): array {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $pageCallback = $decoded['pageCallback'] ?? [];
            $pageProcessor->process(
                (int) ($pageCallback['siteId'] ?? 0),
                (int) ($pageCallback['runId'] ?? 0),
                ['url' => 'https://example.org/stream', 'status' => 200, 'title' => 'Stream', 'text' => 'Entity Alpha appears here']
            );

            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'pages' => [],
                    'stats' => ['returned' => 1],
                    'streamedPages' => true,
                ], JSON_THROW_ON_ERROR),
                'error' => null,
                'curl_failed' => false,
            ];
        }, enableStreaming: true);

        $result = $orchestrator->scanSite($site, ['retry_attempts' => 1, 'retry_delay_ms' => 1]);
        self::assertSame(1, $result['pages_total']);
        self::assertSame(1, $result['pages_with_matches']);

        $pages = (new PageRepository($this->pdo))->recentBySite($siteId, 5);
        self::assertCount(1, $pages);
        $findings = (new FindingRepository($this->pdo))->recentBySite($siteId, 10);
        self::assertCount(2, $findings);
    }

    /** @param callable(string, string): array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} $transport */
    private function buildOrchestrator(callable $transport, bool $enableStreaming = false): CrawlOrchestrator
    {
        $siteRepository = new SiteRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $findingRepository = new FindingRepository($this->pdo);
        $patternCatalog = new PatternCatalog($this->patternsPath);
        return new CrawlOrchestrator(
            $siteRepository,
            new CrawlRunRepository($this->pdo),
            $findingRepository,
            new CrawlerClient(
                'http://crawler.local/crawl',
                $transport,
                '',
                '',
                $enableStreaming ? 'http://app.local/internal/crawl-page' : '',
                $enableStreaming ? 'stream-token' : ''
            ),
            new CrawledPageProcessor($pageRepository, $findingRepository, $patternCatalog)
        );
    }
}
