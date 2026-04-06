<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FindingRepository;
use App\Repository\FindingRevalidationRepository;
use App\Repository\PageRepository;

final class FindingsRevalidator
{
    public function __construct(
        private readonly FindingRepository $findingRepository,
        private readonly PageRepository $pageRepository,
        private readonly FindingRevalidationRepository $findingRevalidationRepository,
    ) {
    }

    /**
     * @return array{checked:int,deleted:int}
     */
    public function revalidateSite(int $siteId, string $patternSource): array
    {
        $total = $this->findingRepository->countBySiteAndPatternSource($siteId, $patternSource);
        $this->findingRevalidationRepository->markRunning($siteId, $patternSource, $total);

        $checked = 0;
        $deleted = 0;
        $pageIdsToSync = [];
        $afterId = 0;
        $batchSize = 500;

        while (true) {
            $findings = $this->findingRepository->listForRevalidation($siteId, $patternSource, $batchSize, $afterId);
            if ($findings === []) {
                break;
            }

            foreach ($findings as $finding) {
                $afterId = max($afterId, (int) ($finding['id'] ?? 0));
                $checked++;
                $shouldDelete = false;
                $pageId = (int) ($finding['page_id'] ?? 0);

                $fragment = trim((string) ($finding['context_excerpt'] ?? ''));
                if ($fragment === '') {
                    $fragment = trim((string) ($finding['matched_text'] ?? ''));
                }
                $findingId = (int) ($finding['id'] ?? 0);
                if ($fragment !== '' && $findingId > 0 && $pageId > 0) {
                    $content = (string) ($finding['page_content'] ?? '');
                    $shouldDelete = !$this->containsFragment($content, $fragment);
                }

                if ($shouldDelete && $this->findingRepository->deleteByIdAndSite($findingId, $siteId)) {
                    $deleted++;
                    $pageIdsToSync[$pageId] = true;
                }

                if ($checked % 25 === 0) {
                    $this->findingRevalidationRepository->updateProgress($siteId, $patternSource, $checked, $deleted);
                }
            }

            if (count($findings) < $batchSize) {
                break;
            }
        }

        foreach (array_keys($pageIdsToSync) as $pageId) {
            $this->pageRepository->syncMatchedState((int) $pageId);
        }

        $this->findingRevalidationRepository->updateProgress($siteId, $patternSource, $checked, $deleted);
        $this->findingRevalidationRepository->markCompleted($siteId, $patternSource, $checked, $deleted);

        return [
            'checked' => $checked,
            'deleted' => $deleted,
        ];
    }

    private function containsFragment(string $content, string $fragment): bool
    {
        $normalizedContent = $this->normalizeText($content);
        $normalizedFragment = $this->normalizeText($fragment);
        if ($normalizedContent === '' || $normalizedFragment === '') {
            return false;
        }

        return mb_stripos($normalizedContent, $normalizedFragment) !== false;
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_strtolower((string) (preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
