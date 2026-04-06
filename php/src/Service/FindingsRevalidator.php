<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FindingRepository;
use App\Repository\PageRepository;

final class FindingsRevalidator
{
    public function __construct(
        private readonly FindingRepository $findingRepository,
        private readonly PageRepository $pageRepository,
    ) {
    }

    /**
     * @return array{checked:int,deleted:int}
     */
    public function revalidateSite(int $siteId, string $patternSource): array
    {
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

                $fragment = trim((string) ($finding['context_excerpt'] ?? ''));
                if ($fragment === '') {
                    $fragment = trim((string) ($finding['matched_text'] ?? ''));
                }
                if ($fragment === '') {
                    continue;
                }

                $content = (string) ($finding['page_content'] ?? '');
                if ($this->containsFragment($content, $fragment)) {
                    continue;
                }

                $findingId = (int) ($finding['id'] ?? 0);
                $pageId = (int) ($finding['page_id'] ?? 0);
                if ($findingId <= 0 || $pageId <= 0) {
                    continue;
                }

                if ($this->findingRepository->deleteByIdAndSite($findingId, $siteId)) {
                    $deleted++;
                    $pageIdsToSync[$pageId] = true;
                }
            }

            if (count($findings) < $batchSize) {
                break;
            }
        }

        foreach (array_keys($pageIdsToSync) as $pageId) {
            $this->pageRepository->syncMatchedState((int) $pageId);
        }

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
