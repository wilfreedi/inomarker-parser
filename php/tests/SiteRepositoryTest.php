<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\SiteRepository;
use App\Tests\Support\DatabaseTestCase;

final class SiteRepositoryTest extends DatabaseTestCase
{
    private SiteRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new SiteRepository($this->pdo);
    }

    public function testCreateNormalizesUrlAndPreventsDuplicates(): void
    {
        $this->repository->create('Site A', 'example.org/');
        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Сайт с таким URL уже существует');
        $this->repository->create('Duplicate Site', 'https://example.org');
    }

    public function testUpdateRejectsDuplicateBaseUrl(): void
    {
        $this->repository->create('Site A', 'https://a.example.org');
        $this->repository->create('Site B', 'https://b.example.org');
        $siteB = 0;
        foreach ($this->repository->all() as $site) {
            if ((string) $site['name'] === 'Site B') {
                $siteB = (int) $site['id'];
                break;
            }
        }
        self::assertGreaterThan(0, $siteB);

        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Сайт с таким URL уже существует');
        $this->repository->update($siteB, 'Site B', 'https://a.example.org');
    }

    public function testPauseResumeAndRequestScanChangeStatusAsExpected(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];

        $this->repository->pause($siteId);
        $paused = $this->repository->findById($siteId);
        self::assertSame('paused', $paused['status']);
        self::assertSame(0, (int) $paused['is_enabled']);

        $this->repository->resume($siteId);
        $resumed = $this->repository->findById($siteId);
        self::assertSame('idle', $resumed['status']);
        self::assertSame(1, (int) $resumed['is_enabled']);

        $this->repository->requestScan($siteId);
        $requested = $this->repository->findById($siteId);
        self::assertSame('idle', $requested['status']);
        self::assertNotNull($requested['scan_requested_at']);
    }

    public function testClaimForScanClaimsOnlyExplicitlyRequestedSites(): void
    {
        $this->repository->create('Site Due', 'https://due.example.org');
        $this->repository->create('Site Paused', 'https://paused.example.org');
        $all = $this->repository->all();
        $pausedSiteId = (int) $all[0]['id'];
        $dueSiteId = (int) $all[1]['id'];

        $this->repository->pause($pausedSiteId);
        $this->repository->requestScan($dueSiteId);
        $claimed = $this->repository->claimForScan(10, 360);

        self::assertCount(1, $claimed);
        self::assertSame($dueSiteId, (int) $claimed[0]['id']);

        $dueSite = $this->repository->findById($dueSiteId);
        self::assertSame('running', $dueSite['status']);
        self::assertNull($dueSite['scan_requested_at']);

        $pausedSite = $this->repository->findById($pausedSiteId);
        self::assertSame('paused', $pausedSite['status']);
    }

    public function testClaimForScanDoesNotAutoClaimSiteWithoutExplicitRequest(): void
    {
        $this->repository->create('Site Auto', 'https://auto.example.org');
        $siteId = (int) $this->repository->all()[0]['id'];

        $claimed = $this->repository->claimForScan(10, 360);

        self::assertSame([], $claimed);
        $site = $this->repository->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('idle', $site['status']);
        self::assertNull($site['scan_requested_at']);
    }

    public function testDeleteRemovesSiteFromStorage(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];

        $this->repository->delete($siteId);
        self::assertNull($this->repository->findById($siteId));
    }

    public function testDeleteFullyCleansRelatedRunsPagesAndFindings(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];

        $this->pdo->prepare(
            "INSERT INTO crawl_runs (site_id, status, started_at, finished_at, pages_total, pages_with_matches, error_message)
             VALUES (:site_id, 'completed', :started_at, :finished_at, 1, 1, NULL)"
        )->execute([
            ':site_id' => $siteId,
            ':started_at' => '2026-04-01 12:00:00',
            ':finished_at' => '2026-04-01 12:01:00',
        ]);
        $runId = (int) $this->pdo->lastInsertId();

        $content = 'Entity';
        $this->pdo->prepare(
            "INSERT INTO pages (site_id, url, title, content, content_hash, http_status, is_matched, matched_entities, crawled_at)
             VALUES (:site_id, :url, :title, :content, :content_hash, 200, 1, :matched_entities, :crawled_at)"
        )->execute([
            ':site_id' => $siteId,
            ':url' => 'https://example.org/page',
            ':title' => 'Page',
            ':content' => $content,
            ':content_hash' => hash('sha256', $content),
            ':matched_entities' => json_encode(['Entity A'], JSON_THROW_ON_ERROR),
            ':crawled_at' => '2026-04-01 12:00:10',
        ]);
        $pageId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO findings (
                run_id, site_id, page_id, category, entity_name, pattern_source,
                matched_text, occurrences, context_excerpt, created_at
             ) VALUES (
                :run_id, :site_id, :page_id, 'foreign_agent', 'Entity A', 'full',
                'Entity A', 1, 'Entity A context', :created_at
             )"
        )->execute([
            ':run_id' => $runId,
            ':site_id' => $siteId,
            ':page_id' => $pageId,
            ':created_at' => '2026-04-01 12:00:20',
        ]);

        $this->repository->delete($siteId);
        self::assertNull($this->repository->findById($siteId));

        $runsCount = (int) $this->pdo->query("SELECT COUNT(*) FROM crawl_runs WHERE site_id = {$siteId}")->fetchColumn();
        $pagesCount = (int) $this->pdo->query("SELECT COUNT(*) FROM pages WHERE site_id = {$siteId}")->fetchColumn();
        $findingsCount = (int) $this->pdo->query("SELECT COUNT(*) FROM findings WHERE site_id = {$siteId}")->fetchColumn();

        self::assertSame(0, $runsCount);
        self::assertSame(0, $pagesCount);
        self::assertSame(0, $findingsCount);
    }

    public function testResetForRecrawlClearsDataAndRequestsNewScan(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];

        $this->pdo->prepare(
            "INSERT INTO crawl_runs (site_id, status, started_at, finished_at, pages_total, pages_with_matches, error_message)
             VALUES (:site_id, 'completed', :started_at, :finished_at, 1, 1, NULL)"
        )->execute([
            ':site_id' => $siteId,
            ':started_at' => '2026-04-01 12:00:00',
            ':finished_at' => '2026-04-01 12:01:00',
        ]);
        $runId = (int) $this->pdo->lastInsertId();

        $content = 'Entity';
        $this->pdo->prepare(
            "INSERT INTO pages (site_id, url, title, content, content_hash, http_status, is_matched, matched_entities, crawled_at)
             VALUES (:site_id, :url, :title, :content, :content_hash, 200, 1, :matched_entities, :crawled_at)"
        )->execute([
            ':site_id' => $siteId,
            ':url' => 'https://example.org/page',
            ':title' => 'Page',
            ':content' => $content,
            ':content_hash' => hash('sha256', $content),
            ':matched_entities' => json_encode(['Entity A'], JSON_THROW_ON_ERROR),
            ':crawled_at' => '2026-04-01 12:00:10',
        ]);
        $pageId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO findings (
                run_id, site_id, page_id, category, entity_name, pattern_source,
                matched_text, occurrences, context_excerpt, created_at
             ) VALUES (
                :run_id, :site_id, :page_id, 'foreign_agent', 'Entity A', 'full',
                'Entity A', 1, 'Entity A context', :created_at
             )"
        )->execute([
            ':run_id' => $runId,
            ':site_id' => $siteId,
            ':page_id' => $pageId,
            ':created_at' => '2026-04-01 12:00:20',
        ]);

        $this->repository->resetForRecrawl($siteId);
        $site = $this->repository->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('idle', $site['status']);
        self::assertSame(1, (int) $site['is_enabled']);
        self::assertNotNull($site['scan_requested_at']);
        self::assertNull($site['last_crawled_at']);
        self::assertNull($site['last_error']);
        self::assertSame(0, (int) ($site['progress_pages'] ?? -1));
        self::assertNull($site['progress_current_url']);
        self::assertNull($site['progress_recent_urls']);
        self::assertNull($site['progress_log']);
        self::assertNull($site['progress_updated_at']);

        $runsCount = (int) $this->pdo->query("SELECT COUNT(*) FROM crawl_runs WHERE site_id = {$siteId}")->fetchColumn();
        $pagesCount = (int) $this->pdo->query("SELECT COUNT(*) FROM pages WHERE site_id = {$siteId}")->fetchColumn();
        $findingsCount = (int) $this->pdo->query("SELECT COUNT(*) FROM findings WHERE site_id = {$siteId}")->fetchColumn();

        self::assertSame(0, $runsCount);
        self::assertSame(0, $pagesCount);
        self::assertSame(0, $findingsCount);
    }

    public function testUpdateProgressWritesCurrentPageForRunningSite(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];
        $this->repository->requestScan($siteId);
        $this->repository->claimForScan(1, 360);

        $this->repository->updateProgress($siteId, 12, 'https://example.org/news/page');
        $this->repository->updateProgress($siteId, 13, 'https://example.org/news/page-2');
        $this->repository->updateProgress($siteId, 14, 'https://example.org/news/page');
        $running = $this->repository->findById($siteId);
        self::assertSame('running', $running['status']);
        self::assertSame(14, (int) $running['progress_pages']);
        self::assertSame('https://example.org/news/page', (string) $running['progress_current_url']);
        self::assertIsString($running['progress_recent_urls']);
        $recent = json_decode((string) $running['progress_recent_urls'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['https://example.org/news/page', 'https://example.org/news/page-2'], $recent);
        self::assertNotNull($running['progress_updated_at']);
        self::assertNull($running['progress_log']);

        $this->repository->markCompleted($siteId);
        $completed = $this->repository->findById($siteId);
        self::assertSame(0, (int) $completed['progress_pages']);
        self::assertNull($completed['progress_current_url']);
        self::assertNull($completed['progress_recent_urls']);
        self::assertNull($completed['progress_updated_at']);
    }

    public function testRecoverManyFromStaleReturnsSiteToQueueWithoutPause(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];
        $this->repository->requestScan($siteId);
        $this->repository->claimForScan(1, 360);
        $this->repository->updateProgress($siteId, 9, 'https://example.org/a');

        $this->repository->recoverManyFromStale([$siteId], 'stale run recovered');
        $site = $this->repository->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('idle', $site['status']);
        self::assertSame(1, (int) $site['is_enabled']);
        self::assertNotNull($site['scan_requested_at']);
        self::assertSame(0, (int) $site['progress_pages']);
        self::assertNull($site['progress_current_url']);
        self::assertNull($site['progress_recent_urls']);
        self::assertNull($site['progress_log']);
        self::assertNull($site['progress_updated_at']);
        self::assertStringContainsString('stale run recovered', (string) $site['last_error']);
    }

    public function testUpdateProgressStoresAndDeduplicatesLiveLogs(): void
    {
        $this->repository->create('Site A', 'https://example.org');
        $siteId = (int) $this->repository->all()[0]['id'];
        $this->repository->requestScan($siteId);
        $this->repository->claimForScan(1, 360);

        $this->repository->updateProgress($siteId, 1, 'https://example.org/a', 'Запуск обхода', 'info');
        $this->repository->updateProgress($siteId, 2, 'https://example.org/b', 'Запуск обхода', 'info');
        $this->repository->updateProgress($siteId, 3, 'https://example.org/c', 'Обнаружен robots.txt', 'debug');
        $this->repository->updateProgress($siteId, 4, 'https://example.org/d', 'Ошибка страницы', 'warn');

        $logs = $this->repository->progressLogs($siteId, 50);
        self::assertCount(3, $logs);
        self::assertSame('info', $logs[0]['level']);
        self::assertSame('Запуск обхода', $logs[0]['message']);
        self::assertSame('debug', $logs[1]['level']);
        self::assertSame('warn', $logs[2]['level']);
    }
}
