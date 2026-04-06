<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\FindingRepository;
use App\Repository\SiteRepository;
use App\Tests\Support\DatabaseTestCase;

final class FindingRepositoryTest extends DatabaseTestCase
{
    public function testPatternSourceReportsAreCalculatedIndependently(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Repo Site', 'https://repo.example.org');
        $site = $siteRepository->all()[0];
        $siteId = (int) $site['id'];
        $runId = $this->insertRun($siteId);
        $pageA = $this->insertPage($siteId, 'https://repo.example.org/a');
        $pageB = $this->insertPage($siteId, 'https://repo.example.org/b');

        $this->insertFinding($runId, $siteId, $pageA, 'foreign_agent', 'Entity Alpha', 'full', 3);
        $this->insertFinding($runId, $siteId, $pageA, 'foreign_agent', 'Entity Alpha', 'short', 1);
        $this->insertFinding($runId, $siteId, $pageB, 'terrorist', 'Entity Beta', 'short', 2);

        $repository = new FindingRepository($this->pdo);

        self::assertSame(3, $repository->countBySite($siteId));
        self::assertSame(1, $repository->countBySiteAndPatternSource($siteId, 'full'));
        self::assertSame(2, $repository->countBySiteAndPatternSource($siteId, 'short'));

        $shortSummary = $repository->summaryBySiteAndPatternSource($siteId, 'short');
        self::assertSame(2, (int) $shortSummary['findings_total']);
        self::assertSame(3, (int) $shortSummary['occurrences_total']);
        self::assertSame(2, (int) $shortSummary['entities_total']);
        self::assertSame(2, (int) $shortSummary['categories_total']);

        $fullFindings = $repository->recentBySiteAndPatternSource($siteId, 'full', 20, 0);
        self::assertCount(1, $fullFindings);
        self::assertSame('full', $fullFindings[0]['pattern_source']);

        $shortCategories = $repository->categoriesBySiteAndPatternSource($siteId, 'short', 20);
        self::assertCount(2, $shortCategories);
    }

    public function testDeleteByIdAndSiteDeletesOnlyTargetFinding(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Site A', 'https://a.example.org');
        $siteRepository->create('Site B', 'https://b.example.org');
        $sites = $siteRepository->all();
        $siteAId = 0;
        $siteBId = 0;
        foreach ($sites as $site) {
            $baseUrl = (string) ($site['base_url'] ?? '');
            if ($baseUrl === 'https://a.example.org') {
                $siteAId = (int) ($site['id'] ?? 0);
            }
            if ($baseUrl === 'https://b.example.org') {
                $siteBId = (int) ($site['id'] ?? 0);
            }
        }
        self::assertGreaterThan(0, $siteAId);
        self::assertGreaterThan(0, $siteBId);

        $runA = $this->insertRun($siteAId);
        $runB = $this->insertRun($siteBId);
        $pageA = $this->insertPage($siteAId, 'https://a.example.org/a');
        $pageB = $this->insertPage($siteBId, 'https://b.example.org/b');

        $findingA = $this->insertFinding($runA, $siteAId, $pageA, 'foreign_agent', 'Entity A', 'full', 1);
        $findingB = $this->insertFinding($runB, $siteBId, $pageB, 'terrorist', 'Entity B', 'short', 2);

        $repository = new FindingRepository($this->pdo);

        self::assertNotNull($repository->findByIdAndSite($findingA, $siteAId));
        self::assertFalse($repository->deleteByIdAndSite($findingA, $siteBId));
        self::assertTrue($repository->deleteByIdAndSite($findingA, $siteAId));
        self::assertNull($repository->findByIdAndSite($findingA, $siteAId));
        self::assertNotNull($repository->findByIdAndSite($findingB, $siteBId));
    }

    private function insertRun(int $siteId): int
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            INSERT INTO crawl_runs (site_id, status, started_at, finished_at, pages_total, pages_with_matches, error_message)
            VALUES (:site_id, :status, :started_at, :finished_at, :pages_total, :pages_with_matches, :error_message)
            SQL
        );
        $stmt->execute([
            ':site_id' => $siteId,
            ':status' => 'completed',
            ':started_at' => '2026-04-01 10:00:00',
            ':finished_at' => '2026-04-01 10:01:00',
            ':pages_total' => 2,
            ':pages_with_matches' => 2,
            ':error_message' => null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertPage(int $siteId, string $url): int
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
            ':title' => 'Title',
            ':content' => $content,
            ':content_hash' => hash('sha256', $content),
            ':http_status' => 200,
            ':crawled_at' => '2026-04-01 10:00:10',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertFinding(
        int $runId,
        int $siteId,
        int $pageId,
        string $category,
        string $entityName,
        string $patternSource,
        int $occurrences,
    ): int {
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
            ':pattern_source' => $patternSource,
            ':matched_text' => $entityName,
            ':occurrences' => $occurrences,
            ':context_excerpt' => $entityName . ' context',
            ':created_at' => '2026-04-01 10:00:20',
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
