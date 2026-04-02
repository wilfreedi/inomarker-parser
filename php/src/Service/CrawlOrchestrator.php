<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CrawlRunRepository;
use App\Repository\FindingRepository;
use App\Repository\PageRepository;
use App\Repository\SiteRepository;

final class CrawlOrchestrator
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly CrawlRunRepository $crawlRunRepository,
        private readonly PageRepository $pageRepository,
        private readonly FindingRepository $findingRepository,
        private readonly CrawlerClient $crawlerClient,
        private readonly PatternCatalog $patternCatalog,
    ) {
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, int> $crawlerOptions
     * @return array{pages_total:int,pages_with_matches:int}
     */
    public function scanSite(array $site, array $crawlerOptions): array
    {
        $siteId = (int) $site['id'];
        $runId = $this->crawlRunRepository->start($siteId);
        $pagesTotal = 0;
        $pagesWithMatches = 0;
        $validPagesTotal = 0;
        $skippedMatchedPages = 0;

        try {
            $pages = $this->crawlerClient->crawl((string) $site['base_url'], [
                ...$crawlerOptions,
                'site_id' => $siteId,
                'run_id' => $runId,
            ]);
            if ($pages === []) {
                throw new \RuntimeException('Crawler returned no pages');
            }
            $matcher = new PatternMatcher($this->patternCatalog->all());

            foreach ($pages as $page) {
                $pagesTotal++;
                if (!$this->isValidCrawledPage($page)) {
                    continue;
                }
                $url = (string) $page['url'];
                $existing = $this->pageRepository->findBySiteAndUrl($siteId, $url);
                if ($existing !== null && (int) ($existing['is_matched'] ?? 0) === 1) {
                    $skippedMatchedPages++;
                    continue;
                }
                $validPagesTotal++;
                $pageId = $this->pageRepository->upsert($siteId, $page);
                $matches = $matcher->match((string) ($page['text'] ?? ''));
                if ($matches !== []) {
                    $pagesWithMatches++;
                    $this->findingRepository->insertBatch($runId, $siteId, $pageId, $matches);
                    $this->pageRepository->markMatched(
                        $pageId,
                        array_values(array_map(
                            static fn (array $match): string => (string) ($match['entity_name'] ?? ''),
                            $matches
                        ))
                    );
                }
            }

            if ($validPagesTotal === 0) {
                if ($skippedMatchedPages > 0) {
                    $this->crawlRunRepository->finish($runId, 'completed', $pagesTotal, 0, null);
                    $this->siteRepository->markCompleted($siteId);

                    return [
                        'pages_total' => $pagesTotal,
                        'pages_with_matches' => 0,
                    ];
                }
                throw new \RuntimeException('Crawler returned no valid pages');
            }

            $this->crawlRunRepository->finish($runId, 'completed', $pagesTotal, $pagesWithMatches, null);
            $this->siteRepository->markCompleted($siteId);

            return [
                'pages_total' => $pagesTotal,
                'pages_with_matches' => $pagesWithMatches,
            ];
        } catch (\Throwable $exception) {
            $this->crawlRunRepository->finish($runId, 'failed', $pagesTotal, $pagesWithMatches, $exception->getMessage());
            $this->siteRepository->markFailed($siteId, $exception->getMessage() . ' | Сайт автоматически поставлен на паузу.');
            throw $exception;
        }
    }

    /** @param array<string, mixed> $page */
    private function isValidCrawledPage(array $page): bool
    {
        $url = trim((string) ($page['url'] ?? ''));
        if ($url === '') {
            return false;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        return true;
    }
}
