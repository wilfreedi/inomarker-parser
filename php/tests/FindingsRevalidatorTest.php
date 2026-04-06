<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\FindingRepository;
use App\Repository\PageRepository;
use App\Repository\SiteRepository;
use App\Service\FindingsRevalidator;
use App\Tests\Support\DatabaseTestCase;

final class FindingsRevalidatorTest extends DatabaseTestCase
{
    public function testDeletesFindingsWhenFragmentIsMissingFromCurrentPageContent(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://a.example.org');
        $siteId = (int) ($siteRepository->all()[0]['id'] ?? 0);
        $runId = $this->insertRun($siteId);
        $pageId = $this->insertPage($siteId, 'https://a.example.org/a', 'Text with actual fragment here');
        $missingFindingId = $this->insertFinding($runId, $siteId, $pageId, 'full', 'Removed fragment');
        $existingFindingId = $this->insertFinding($runId, $siteId, $pageId, 'full', 'actual fragment');

        $service = new FindingsRevalidator(
            new FindingRepository($this->pdo),
            new PageRepository($this->pdo)
        );

        $result = $service->revalidateSite($siteId, 'full');

        self::assertSame(['checked' => 2, 'deleted' => 1], $result);
        self::assertNull((new FindingRepository($this->pdo))->findByIdAndSite($missingFindingId, $siteId));
        self::assertNotNull((new FindingRepository($this->pdo))->findByIdAndSite($existingFindingId, $siteId));
    }

    private function insertRun(int $siteId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO crawl_runs (site_id, status, started_at, finished_at, pages_total, pages_with_matches, error_message)
             VALUES (:site_id, :status, :started_at, :finished_at, :pages_total, :pages_with_matches, :error_message)'
        );
        $stmt->execute([
            ':site_id' => $siteId,
            ':status' => 'completed',
            ':started_at' => '2026-04-01 10:00:00',
            ':finished_at' => '2026-04-01 10:01:00',
            ':pages_total' => 1,
            ':pages_with_matches' => 1,
            ':error_message' => null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertPage(int $siteId, string $url, string $content): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (site_id, url, title, content, content_hash, http_status, is_matched, crawled_at)
             VALUES (:site_id, :url, :title, :content, :content_hash, :http_status, :is_matched, :crawled_at)'
        );
        $stmt->execute([
            ':site_id' => $siteId,
            ':url' => $url,
            ':title' => 'Title',
            ':content' => $content,
            ':content_hash' => hash('sha256', $content),
            ':http_status' => 200,
            ':is_matched' => 1,
            ':crawled_at' => '2026-04-01 10:00:10',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertFinding(int $runId, int $siteId, int $pageId, string $patternSource, string $fragment): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO findings (
                run_id, site_id, page_id, category, entity_name, pattern_source,
                matched_text, occurrences, context_excerpt, created_at
            ) VALUES (
                :run_id, :site_id, :page_id, :category, :entity_name, :pattern_source,
                :matched_text, :occurrences, :context_excerpt, :created_at
            )'
        );
        $stmt->execute([
            ':run_id' => $runId,
            ':site_id' => $siteId,
            ':page_id' => $pageId,
            ':category' => 'foreign_agent',
            ':entity_name' => $fragment,
            ':pattern_source' => $patternSource,
            ':matched_text' => $fragment,
            ':occurrences' => 1,
            ':context_excerpt' => $fragment,
            ':created_at' => '2026-04-01 10:00:20',
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
