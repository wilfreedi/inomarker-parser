<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FindingRepository;
use App\Repository\PageRepository;

final class CrawledPageProcessor
{
    private ?PatternMatcher $matcher = null;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly FindingRepository $findingRepository,
        private readonly PatternCatalog $patternCatalog,
    ) {
    }

    /**
     * @param array<string, mixed> $page
     * @return array{processed:bool,skipped_matched:bool,has_matches:bool,page_id:int|null,url:string}
     */
    public function process(int $siteId, int $runId, array $page): array
    {
        $url = trim((string) ($page['url'] ?? ''));
        if (!$this->isValidCrawledPage($page)) {
            return [
                'processed' => false,
                'skipped_matched' => false,
                'has_matches' => false,
                'page_id' => null,
                'url' => $url,
            ];
        }

        $existing = $this->pageRepository->findBySiteAndUrl($siteId, $url);
        if ($existing !== null && (int) ($existing['is_matched'] ?? 0) === 1) {
            return [
                'processed' => false,
                'skipped_matched' => true,
                'has_matches' => false,
                'page_id' => (int) ($existing['id'] ?? 0),
                'url' => $url,
            ];
        }

        $pageId = $this->pageRepository->upsert($siteId, $page);
        $this->findingRepository->deleteByRunAndPage($runId, $pageId);

        $matches = $this->matcher()->match((string) ($page['text'] ?? ''));
        if ($matches !== []) {
            $this->findingRepository->insertBatch($runId, $siteId, $pageId, $matches);
            $this->pageRepository->markMatched(
                $pageId,
                array_values(array_map(
                    static fn (array $match): string => (string) ($match['entity_name'] ?? ''),
                    $matches
                ))
            );
        }

        return [
            'processed' => true,
            'skipped_matched' => false,
            'has_matches' => $matches !== [],
            'page_id' => $pageId,
            'url' => $url,
        ];
    }

    private function matcher(): PatternMatcher
    {
        if ($this->matcher === null) {
            $this->matcher = new PatternMatcher($this->patternCatalog->all(true));
        }

        return $this->matcher;
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
