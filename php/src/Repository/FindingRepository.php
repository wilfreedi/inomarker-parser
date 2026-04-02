<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class FindingRepository
{
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
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
              f.id,
              f.created_at,
              f.category,
              f.entity_name,
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
            ORDER BY f.id DESC
            LIMIT :limit
            OFFSET :offset
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countBySite(int $siteId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM findings WHERE site_id = :site_id');
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function topEntitiesBySite(int $siteId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                entity_name,
                category,
                COUNT(*) AS finding_rows,
                COALESCE(SUM(occurrences), 0) AS total_occurrences
            FROM findings
            WHERE site_id = :site_id
            GROUP BY entity_name, category
            ORDER BY total_occurrences DESC, finding_rows DESC, entity_name ASC
            LIMIT :limit
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function summaryBySite(int $siteId): array
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                COUNT(*) AS findings_total,
                COALESCE(SUM(occurrences), 0) AS occurrences_total,
                COUNT(DISTINCT entity_name) AS entities_total,
                COUNT(DISTINCT category) AS categories_total
            FROM findings
            WHERE site_id = :site_id
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
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
}
