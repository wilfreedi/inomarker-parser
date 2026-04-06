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
        private readonly PatternCatalog $patternCatalog,
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
        $updated = 0;
        $pageIdsToSync = [];
        $afterId = 0;
        $batchSize = 500;
        $pageMatchesCache = [];
        $matcher = new PatternMatcher($this->patternsForSource($patternSource));

        while (true) {
            $findings = $this->findingRepository->listForRevalidation($siteId, $patternSource, $batchSize, $afterId);
            if ($findings === []) {
                break;
            }

            foreach ($findings as $finding) {
                $afterId = max($afterId, (int) ($finding['id'] ?? 0));
                $checked++;
                $pageId = (int) ($finding['page_id'] ?? 0);
                if ($pageId <= 0) {
                    continue;
                }
                $findingId = (int) ($finding['id'] ?? 0);
                if ($findingId <= 0) {
                    continue;
                }

                if (!isset($pageMatchesCache[$pageId])) {
                    $content = (string) ($finding['page_content'] ?? '');
                    $pageMatchesCache[$pageId] = $this->indexMatchesByKey($matcher->match($content));
                }

                $matchKey = $this->buildMatchKey(
                    (string) ($finding['category'] ?? ''),
                    (string) ($finding['entity_name'] ?? ''),
                    (string) ($finding['pattern_source'] ?? '')
                );
                $currentMatch = $pageMatchesCache[$pageId][$matchKey] ?? null;

                if (!is_array($currentMatch)) {
                    if ($this->findingRepository->deleteByIdAndSite($findingId, $siteId)) {
                        $deleted++;
                        $pageIdsToSync[$pageId] = true;
                    }
                } else {
                    $didUpdate = $this->findingRepository->updateMatchDataByIdAndSite($findingId, $siteId, [
                        'matched_text' => (string) ($currentMatch['matched_text'] ?? ''),
                        'occurrences' => (int) ($currentMatch['occurrences'] ?? 1),
                        'context_excerpt' => (string) ($currentMatch['context_excerpt'] ?? ''),
                    ]);
                    if ($didUpdate) {
                        $updated++;
                    }
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

    /** @return array<int, PatternDefinition> */
    private function patternsForSource(string $patternSource): array
    {
        $patterns = $this->patternCatalog->all(true);

        return array_values(array_filter(
            $patterns,
            static fn (PatternDefinition $pattern): bool => $pattern->source === $patternSource
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, array<string, mixed>>
     */
    private function indexMatchesByKey(array $matches): array
    {
        $indexed = [];
        foreach ($matches as $match) {
            $indexed[$this->buildMatchKey(
                (string) ($match['category'] ?? ''),
                (string) ($match['entity_name'] ?? ''),
                (string) ($match['pattern_source'] ?? '')
            )] = $match;
        }

        return $indexed;
    }

    private function buildMatchKey(string $category, string $entityName, string $patternSource): string
    {
        return $category . '|' . $entityName . '|' . $patternSource;
    }
}
