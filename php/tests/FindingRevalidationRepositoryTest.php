<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\FindingRevalidationRepository;
use App\Repository\SiteRepository;
use App\Tests\Support\DatabaseTestCase;

final class FindingRevalidationRepositoryTest extends DatabaseTestCase
{
    public function testQueuedRunningAndCompletedStateLifecycle(): void
    {
        $siteRepository = new SiteRepository($this->pdo);
        $siteRepository->create('Status Site', 'https://status.example.org');
        $siteId = (int) ($siteRepository->all()[0]['id'] ?? 0);

        $repository = new FindingRevalidationRepository($this->pdo);

        $repository->markQueued($siteId, 'full', 12);
        $queued = $repository->findBySiteAndPatternSource($siteId, 'full');
        self::assertSame('queued', $queued['status']);
        self::assertSame(12, (int) $queued['total_findings']);

        $repository->markRunning($siteId, 'full', 12);
        $repository->updateProgress($siteId, 'full', 5, 2);
        $repository->markCompleted($siteId, 'full', 12, 3);

        $completed = $repository->findBySiteAndPatternSource($siteId, 'full');
        self::assertSame('completed', $completed['status']);
        self::assertSame(12, (int) $completed['checked_findings']);
        self::assertSame(3, (int) $completed['deleted_findings']);
    }
}
