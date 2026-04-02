<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\CrawlRunRepository;
use App\Repository\SiteRepository;
use App\Tests\Support\DatabaseTestCase;

final class CrawlRunRepositoryTest extends DatabaseTestCase
{
    public function testFailStaleRunningSkipsRunWithRecentProgress(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $runs = new CrawlRunRepository($this->pdo);

        $siteRepository->create('Site A', 'https://example.org');
        $siteId = (int) $siteRepository->all()[0]['id'];
        $siteRepository->requestScan($siteId);
        $siteRepository->claimForScan(1, 360);

        $runId = $runs->start($siteId);
        $this->pdo->prepare(
            "UPDATE crawl_runs
             SET started_at = :started_at
             WHERE id = :id"
        )->execute([
            ':started_at' => gmdate('Y-m-d H:i:s', time() - (10 * 60)),
            ':id' => $runId,
        ]);
        $this->pdo->prepare(
            "UPDATE sites
             SET progress_updated_at = :progress_updated_at,
                 status = 'running'
             WHERE id = :id"
        )->execute([
            ':progress_updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $siteId,
        ]);

        $staleSiteIds = $runs->failStaleRunning(5, 'stale');
        self::assertSame([], $staleSiteIds);

        $row = $this->pdo->query("SELECT status FROM crawl_runs WHERE id = {$runId}")->fetch();
        self::assertIsArray($row);
        self::assertSame('running', (string) $row['status']);
    }

    public function testFailStaleRunningFailsRunWithoutRecentProgress(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $runs = new CrawlRunRepository($this->pdo);

        $siteRepository->create('Site A', 'https://example.org');
        $siteId = (int) $siteRepository->all()[0]['id'];
        $siteRepository->requestScan($siteId);
        $siteRepository->claimForScan(1, 360);

        $runId = $runs->start($siteId);
        $oldTs = gmdate('Y-m-d H:i:s', time() - (10 * 60));
        $this->pdo->prepare(
            "UPDATE crawl_runs
             SET started_at = :started_at
             WHERE id = :id"
        )->execute([
            ':started_at' => $oldTs,
            ':id' => $runId,
        ]);
        $this->pdo->prepare(
            "UPDATE sites
             SET progress_updated_at = :progress_updated_at,
                 status = 'running'
             WHERE id = :id"
        )->execute([
            ':progress_updated_at' => $oldTs,
            ':id' => $siteId,
        ]);

        $staleSiteIds = $runs->failStaleRunning(5, 'stale');
        self::assertSame([$siteId], $staleSiteIds);

        $row = $this->pdo->query("SELECT status, error_message FROM crawl_runs WHERE id = {$runId}")->fetch();
        self::assertIsArray($row);
        self::assertSame('failed', (string) $row['status']);
        self::assertStringContainsString('stale', (string) $row['error_message']);
    }
}

