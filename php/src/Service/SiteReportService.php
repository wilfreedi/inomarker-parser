<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FindingRepository;
use App\Repository\PageRepository;
use App\Repository\RunRepository;
use App\Repository\SiteRepository;

final class SiteReportService
{
    private const PAGES_PER_PAGE = 25;
    private const FINDINGS_PER_PAGE = 25;

    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly RunRepository $runRepository,
        private readonly PageRepository $pageRepository,
        private readonly FindingRepository $findingRepository,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function build(int $siteId, int $pagesPage = 1, int $findingsPage = 1): ?array
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            return null;
        }

        $pagesPagination = $this->buildPagination(
            $this->pageRepository->countBySite($siteId),
            self::PAGES_PER_PAGE,
            $pagesPage
        );
        $findingsPagination = $this->buildPagination(
            $this->findingRepository->countBySite($siteId),
            self::FINDINGS_PER_PAGE,
            $findingsPage
        );

        return [
            'site' => $site,
            'runs_summary' => $this->runRepository->summaryBySite($siteId),
            'pages_summary' => $this->pageRepository->summaryBySite($siteId),
            'findings_summary' => $this->findingRepository->summaryBySite($siteId),
            'recent_runs' => $this->runRepository->recentBySite($siteId, 20),
            'recent_pages' => $this->pageRepository->recentBySite(
                $siteId,
                $pagesPagination['per_page'],
                ($pagesPagination['current_page'] - 1) * $pagesPagination['per_page']
            ),
            'pages_pagination' => $pagesPagination,
            'top_entities' => $this->findingRepository->topEntitiesBySite($siteId, 20),
            'recent_findings' => $this->findingRepository->recentBySite(
                $siteId,
                $findingsPagination['per_page'],
                ($findingsPagination['current_page'] - 1) * $findingsPagination['per_page']
            ),
            'findings_pagination' => $findingsPagination,
        ];
    }

    /** @return array<string, int|bool> */
    private function buildPagination(int $totalItems, int $perPage, int $requestedPage): array
    {
        $perPage = max(1, $perPage);
        $totalItems = max(0, $totalItems);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = max(1, min($totalPages, $requestedPage));
        $hasPrev = $currentPage > 1;
        $hasNext = $currentPage < $totalPages;

        return [
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'has_prev' => $hasPrev,
            'has_next' => $hasNext,
            'prev_page' => $hasPrev ? $currentPage - 1 : 1,
            'next_page' => $hasNext ? $currentPage + 1 : $totalPages,
            'start_page' => max(1, $currentPage - 2),
            'end_page' => min($totalPages, $currentPage + 2),
        ];
    }
}
