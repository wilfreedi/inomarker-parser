<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class FindingRepository
{
    /** @var array<int, string> */
    private const ALLOWED_PATTERN_SOURCES = ['full', 'short'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<int, array<string, mixed>> $matches */
    public function insertBatch(int $runId, int $siteId, int $pageId, array $matches): void
    {
        if ($matches === []) {
            return;
        }

        $sql = <<<SQL
        INSERT INTO findings (
            run_id, site_id, page_id, category, entity_name, pattern_source,
            matched_text, occurrences, context_excerpt, created_at
        ) VALUES (
            :run_id, :site_id, :page_id, :category, :entity_name, :pattern_source,
            :matched_text, :occurrences, :context_excerpt, :created_at
        )
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $now = gmdate('Y-m-d H:i:s');

        foreach ($matches as $match) {
            $stmt->execute([
                ':run_id' => $runId,
                ':site_id' => $siteId,
                ':page_id' => $pageId,
                ':category' => (string) $match['category'],
                ':entity_name' => (string) $match['entity_name'],
                ':pattern_source' => (string) $match['pattern_source'],
                ':matched_text' => (string) $match['matched_text'],
                ':occurrences' => (int) $match['occurrences'],
                ':context_excerpt' => (string) $match['context_excerpt'],
                ':created_at' => $now,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
              f.id,
              f.created_at,
              f.category,
              f.entity_name,
              f.pattern_source,
              f.occurrences,
              f.matched_text,
              f.context_excerpt,
              p.url AS page_url,
              s.name AS site_name,
              s.base_url AS site_url
            FROM findings f
            INNER JOIN pages p ON p.id = f.page_id
            INNER JOIN sites s ON s.id = f.site_id
            ORDER BY f.id DESC
            LIMIT :limit
            SQL
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentBySite(int $siteId, int $limit = 200, int $offset = 0): array
    {
        return $this->fetchFindingsBySite($siteId, $limit, $offset);
    }

    /** @return array<int, array<string, mixed>> */
    public function recentBySiteAndPatternSource(
        int $siteId,
        string $patternSource,
        int $limit = 200,
        int $offset = 0
    ): array {
        return $this->fetchFindingsBySite($siteId, $limit, $offset, $patternSource);
    }

    public function countBySite(int $siteId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM findings WHERE site_id = :site_id');
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function countBySiteAndPatternSource(int $siteId, string $patternSource): int
    {
        $normalizedSource = $this->normalizePatternSource($patternSource);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM findings WHERE site_id = :site_id AND pattern_source = :pattern_source'
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern_source', $normalizedSource, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findByIdAndSite(int $findingId, int $siteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, site_id, page_id, pattern_source FROM findings WHERE id = :id AND site_id = :site_id LIMIT 1'
        );
        $stmt->bindValue(':id', $findingId, PDO::PARAM_INT);
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function deleteByIdAndSite(int $findingId, int $siteId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM findings WHERE id = :id AND site_id = :site_id');
        $stmt->bindValue(':id', $findingId, PDO::PARAM_INT);
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForRevalidation(
        int $siteId,
        string $patternSource,
        int $limit = 500,
        int $afterId = 0
    ): array {
        $limit = max(1, $limit);
        $afterId = max(0, $afterId);
        $normalizedSource = $this->normalizePatternSource($patternSource);
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                f.id,
                f.site_id,
                f.page_id,
                f.pattern_source,
                f.matched_text,
                f.context_excerpt,
                p.content AS page_content
            FROM findings f
            INNER JOIN pages p ON p.id = f.page_id
            WHERE f.site_id = :site_id
              AND f.pattern_source = :pattern_source
              AND f.id > :after_id
            ORDER BY f.id ASC
            LIMIT :limit
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern_source', $normalizedSource, PDO::PARAM_STR);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topEntitiesBySite(int $siteId, int $limit = 20): array
    {
        return $this->topEntitiesBySiteInternal($siteId, $limit, null);
    }

    /** @return array<int, array<string, mixed>> */
    public function topEntitiesBySiteAndPatternSource(int $siteId, string $patternSource, int $limit = 20): array
    {
        return $this->topEntitiesBySiteInternal($siteId, $limit, $patternSource);
    }

    /** @return array<int, array<string, mixed>> */
    public function categoriesBySiteAndPatternSource(int $siteId, string $patternSource, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $normalizedSource = $this->normalizePatternSource($patternSource);
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                category,
                COUNT(*) AS finding_rows,
                COUNT(DISTINCT entity_name) AS entities_total,
                COALESCE(SUM(occurrences), 0) AS total_occurrences
            FROM findings
            WHERE site_id = :site_id
              AND pattern_source = :pattern_source
            GROUP BY category
            ORDER BY total_occurrences DESC, finding_rows DESC, category ASC
            LIMIT :limit
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern_source', $normalizedSource, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function summaryBySite(int $siteId): array
    {
        return $this->summaryBySiteInternal($siteId, null);
    }

    /** @return array<string, mixed> */
    public function summaryBySiteAndPatternSource(int $siteId, string $patternSource): array
    {
        return $this->summaryBySiteInternal($siteId, $patternSource);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFindingsBySite(
        int $siteId,
        int $limit = 200,
        int $offset = 0,
        ?string $patternSource = null
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $normalizedSource = $patternSource !== null ? $this->normalizePatternSource($patternSource) : null;
        $sql = <<<SQL
            SELECT
              f.id,
              f.created_at,
              f.category,
              f.entity_name,
              f.pattern_source,
              f.occurrences,
              f.matched_text,
              f.context_excerpt,
              p.url AS page_url,
              s.name AS site_name,
              s.base_url AS site_url
            FROM findings f
            INNER JOIN pages p ON p.id = f.page_id
            INNER JOIN sites s ON s.id = f.site_id
            WHERE f.site_id = :site_id
        SQL;
        if ($normalizedSource !== null) {
            $sql .= ' AND f.pattern_source = :pattern_source';
        }
        $sql .= ' ORDER BY f.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        if ($normalizedSource !== null) {
            $stmt->bindValue(':pattern_source', $normalizedSource, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topEntitiesBySiteInternal(int $siteId, int $limit = 20, ?string $patternSource = null): array
    {
        $limit = max(1, $limit);
        $normalizedSource = $patternSource !== null ? $this->normalizePatternSource($patternSource) : null;
        $sql = <<<SQL
            SELECT
                entity_name,
                category,
                COUNT(*) AS finding_rows,
                COALESCE(SUM(occurrences), 0) AS total_occurrences
            FROM findings
            WHERE site_id = :site_id
        SQL;
        if ($normalizedSource !== null) {
            $sql .= ' AND pattern_source = :pattern_source';
        }
        $sql .= '
            GROUP BY entity_name, category
            ORDER BY total_occurrences DESC, finding_rows DESC, entity_name ASC
            LIMIT :limit
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        if ($normalizedSource !== null) {
            $stmt->bindValue(':pattern_source', $normalizedSource, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    private function summaryBySiteInternal(int $siteId, ?string $patternSource = null): array
    {
        $normalizedSource = $patternSource !== null ? $this->normalizePatternSource($patternSource) : null;
        $sql = <<<SQL
            SELECT
                COUNT(*) AS findings_total,
                COALESCE(SUM(occurrences), 0) AS occurrences_total,
                COUNT(DISTINCT entity_name) AS entities_total,
                COUNT(DISTINCT category) AS categories_total
            FROM findings
            WHERE site_id = :site_id
        SQL;
        if ($normalizedSource !== null) {
            $sql .= ' AND pattern_source = :pattern_source';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        if ($normalizedSource !== null) {
            $stmt->bindValue(':pattern_source', $normalizedSource, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return [
                'findings_total' => 0,
                'occurrences_total' => 0,
                'entities_total' => 0,
                'categories_total' => 0,
            ];
        }

        return $row;
    }

    private function normalizePatternSource(string $patternSource): string
    {
        $normalized = trim(mb_strtolower($patternSource));
        if (!in_array($normalized, self::ALLOWED_PATTERN_SOURCES, true)) {
            throw new \InvalidArgumentException("Unsupported pattern source: {$patternSource}");
        }

        return $normalized;
    }
}
