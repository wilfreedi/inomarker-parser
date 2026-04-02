<?php

declare(strict_types=1);

namespace App\Service;

final class PatternMatcher
{
    /** @param array<int, PatternDefinition> $patterns */
    public function __construct(private readonly array $patterns)
    {
    }

    /**
     * @return array<int, array{
     *   category: string,
     *   entity_name: string,
     *   pattern_source: string,
     *   matched_text: string,
     *   occurrences: int,
     *   context_excerpt: string
     * }>
     */
    public function match(string $text): array
    {
        $normalizedText = trim($text);
        if ($normalizedText === '') {
            return [];
        }

        $results = [];
        foreach ($this->patterns as $patternDefinition) {
            $pattern = $patternDefinition->toPregPattern();
            $matchCount = @preg_match_all($pattern, $normalizedText, $matches, PREG_OFFSET_CAPTURE);
            if ($matchCount === false || $matchCount === 0) {
                continue;
            }

            $firstMatch = $matches[0][0][0] ?? '';
            $firstOffset = (int) ($matches[0][0][1] ?? 0);
            $resultKey = $patternDefinition->category . '|' . $patternDefinition->entityName;

            if (isset($results[$resultKey])) {
                $results[$resultKey]['occurrences'] += $matchCount;
                continue;
            }

            $results[$resultKey] = [
                'category' => $patternDefinition->category,
                'entity_name' => $patternDefinition->entityName,
                'pattern_source' => $patternDefinition->source,
                'matched_text' => mb_substr(trim((string) $firstMatch), 0, 220),
                'occurrences' => $matchCount,
                'context_excerpt' => $this->extractContext($normalizedText, $firstOffset, 180),
            ];
        }

        return array_values($results);
    }

    private function extractContext(string $text, int $byteOffset, int $radius): string
    {
        $start = max(0, $byteOffset - $radius);
        $length = $radius * 2;
        $excerpt = mb_strcut($text, $start, $length, 'UTF-8');
        $excerpt = preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt;

        return trim((string) $excerpt);
    }
}
