<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\FindingRepository;
use App\Repository\PageRepository;
use App\Repository\RunRepository;
use App\Repository\SiteRepository;
use App\Service\SiteReportService;
use App\Tests\Support\DatabaseTestCase;

final class SiteReportServiceTest extends DatabaseTestCase
{
    public function testBuildReturnsAggregatedSiteReport(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Report Site', 'https://report.example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $this->insertRun($siteId, 'completed', '2026-04-01 10:00:00', '2026-04-01 10:01:00', 3, 2, null);
        $runId = $this->insertRun($siteId, 'failed', '2026-04-01 11:00:00', '2026-04-01 11:01:00', 2, 0, 'crawler timeout');

        $pageA = $this->insertPage($siteId, 'https://report.example.org/a', 'A title', 200, '2026-04-01 10:00:10');
        $pageB = $this->insertPage($siteId, 'https://report.example.org/b', 'B title', 503, '2026-04-01 11:00:10');

        $this->insertFinding($runId, $siteId, $pageA, 'foreign_agent', 'Entity Alpha', 3, '2026-04-01 10:00:20');
        $this->insertFinding($runId, $siteId, $pageB, 'foreign_agent', 'Entity Alpha', 1, '2026-04-01 11:00:20');
        $this->insertFinding($runId, $siteId, $pageB, 'terrorist', 'Entity Beta', 2, '2026-04-01 11:00:30');

        $service = new SiteReportService(
            $siteRepository,
            new RunRepository($this->pdo),
            new PageRepository($this->pdo),
            new FindingRepository($this->pdo)
        );

        $report = $service->build($siteId);
        self::assertIsArray($report);

        self::assertSame('Report Site', $report['site']['name']);
        self::assertSame(2, (int) $report['runs_summary']['runs_total']);
        self::assertSame(1, (int) $report['runs_summary']['runs_completed']);
        self::assertSame(1, (int) $report['runs_summary']['runs_failed']);
        self::assertSame('failed', $report['runs_summary']['last_run_status']);

        self::assertSame(2, (int) $report['pages_summary']['pages_indexed']);
        self::assertSame(1, (int) $report['pages_summary']['pages_with_http_errors']);

        self::assertSame(3, (int) $report['findings_summary']['findings_total']);
        self::assertSame(6, (int) $report['findings_summary']['occurrences_total']);
        self::assertSame(2, (int) $report['findings_summary']['entities_total']);

        self::assertCount(2, $report['top_entities']);
        self::assertSame('Entity Alpha', $report['top_entities'][0]['entity_name']);
        self::assertSame(4, (int) $report['top_entities'][0]['total_occurrences']);
        self::assertSame(1, (int) $report['pages_pagination']['current_page']);
        self::assertSame(1, (int) $report['pages_pagination']['total_pages']);
        self::assertSame(1, (int) $report['findings_pagination']['current_page']);
        self::assertSame(1, (int) $report['findings_pagination']['total_pages']);
    }

    public function testBuildReturnsNullForMissingSite(): void
    {
        $service = new SiteReportService(
            new SiteRepository($this->pdo),
            new RunRepository($this->pdo),
            new PageRepository($this->pdo),
            new FindingRepository($this->pdo)
        );

        self::assertNull($service->build(999999));
    }

    public function testBuildAppliesPaginationForPagesAndFindings(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Paginated Site', 'https://pages.example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];

        $runId = $this->insertRun(
            $siteId,
            'completed',
            '2026-04-01 10:00:00',
            '2026-04-01 10:10:00',
            30,
            30,
            null
        );

        for ($i = 1; $i <= 30; $i++) {
            $url = "https://pages.example.org/page-{$i}";
            $crawledAt = sprintf('2026-04-01 10:%02d:00', min(59, $i));
            $pageId = $this->insertPage($siteId, $url, "Title {$i}", 200, $crawledAt);
            $this->insertFinding($runId, $siteId, $pageId, 'foreign_agent', "Entity {$i}", 1, $crawledAt);
        }

        $service = new SiteReportService(
            $siteRepository,
            new RunRepository($this->pdo),
            new PageRepository($this->pdo),
            new FindingRepository($this->pdo)
        );
        $report = $service->build($siteId, 2, 2);
        self::assertIsArray($report);

        self::assertSame(30, (int) $report['pages_pagination']['total_items']);
        self::assertSame(2, (int) $report['pages_pagination']['total_pages']);
        self::assertSame(2, (int) $report['pages_pagination']['current_page']);
        self::assertCount(5, $report['recent_pages']);
        self::assertStringContainsString('/page-5', (string) $report['recent_pages'][0]['url']);

        self::assertSame(30, (int) $report['findings_pagination']['total_items']);
        self::assertSame(2, (int) $report['findings_pagination']['total_pages']);
        self::assertSame(2, (int) $report['findings_pagination']['current_page']);
        self::assertCount(5, $report['recent_findings']);
        self::assertStringContainsString('/page-5', (string) $report['recent_findings'][0]['page_url']);
    }

    private function insertRun(
        int $siteId,
        string $status,
        string $startedAt,
        ?string $finishedAt,
        int $pagesTotal,
        int $pagesWithMatches,
        ?string $errorMessage,
    ): int {
        $stmt = $this->pdo->prepare(
            <<<SQL
            INSERT INTO crawl_runs (site_id, status, started_at, finished_at, pages_total, pages_with_matches, error_message)
            VALUES (:site_id, :status, :started_at, :finished_at, :pages_total, :pages_with_matches, :error_message)
            SQL
        );
        $stmt->execute([
            ':site_id' => $siteId,
            ':status' => $status,
            ':started_at' => $startedAt,
            ':finished_at' => $finishedAt,
            ':pages_total' => $pagesTotal,
            ':pages_with_matches' => $pagesWithMatches,
            ':error_message' => $errorMessage,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertPage(int $siteId, string $url, string $title, int $httpStatus, string $crawledAt): int
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            INSERT INTO pages (site_id, url, title, content, content_hash, http_status, crawled_at)
            VALUES (:site_id, :url, :title, :content, :content_hash, :http_status, :crawled_at)
            SQL
        );
        $content = 'content:' . $url;
        $stmt->execute([
            ':site_id' => $siteId,
            ':url' => $url,
            ':title' => $title,
            ':content' => $content,
            ':content_hash' => hash('sha256', $content),
            ':http_status' => $httpStatus,
            ':crawled_at' => $crawledAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertFinding(
        int $runId,
        int $siteId,
        int $pageId,
        string $category,
        string $entityName,
        int $occurrences,
        string $createdAt,
    ): void {
        $stmt = $this->pdo->prepare(
            <<<SQL
            INSERT INTO findings (
                run_id, site_id, page_id, category, entity_name, pattern_source,
                matched_text, occurrences, context_excerpt, created_at
            ) VALUES (
                :run_id, :site_id, :page_id, :category, :entity_name, :pattern_source,
                :matched_text, :occurrences, :context_excerpt, :created_at
            )
            SQL
        );
        $stmt->execute([
            ':run_id' => $runId,
            ':site_id' => $siteId,
            ':page_id' => $pageId,
            ':category' => $category,
            ':entity_name' => $entityName,
            ':pattern_source' => 'test',
            ':matched_text' => $entityName,
            ':occurrences' => $occurrences,
            ':context_excerpt' => $entityName . ' context',
            ':created_at' => $createdAt,
        ]);
    }
}
