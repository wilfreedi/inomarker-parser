<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class RunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
              r.id,
              r.site_id,
              r.status,
              r.started_at,
              r.finished_at,
              r.pages_total,
              r.pages_with_matches,
              r.error_message,
              s.name AS site_name
            FROM crawl_runs r
            INNER JOIN sites s ON s.id = r.site_id
            ORDER BY r.id DESC
            LIMIT :limit
            SQL
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentBySite(int $siteId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
              id,
              site_id,
              status,
              started_at,
              finished_at,
              pages_total,
              pages_with_matches,
              error_message
            FROM crawl_runs
            WHERE site_id = :site_id
            ORDER BY id DESC
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
                COUNT(*) AS runs_total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS runs_completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS runs_failed,
                COALESCE(SUM(pages_total), 0) AS pages_total,
                COALESCE(SUM(pages_with_matches), 0) AS pages_with_matches,
                (
                    SELECT status
                    FROM crawl_runs
                    WHERE site_id = :site_id_sub_1
                    ORDER BY id DESC
                    LIMIT 1
                ) AS last_run_status,
                (
                    SELECT started_at
                    FROM crawl_runs
                    WHERE site_id = :site_id_sub_2
                    ORDER BY id DESC
                    LIMIT 1
                ) AS last_run_started_at,
                (
                    SELECT finished_at
                    FROM crawl_runs
                    WHERE site_id = :site_id_sub_3
                    ORDER BY id DESC
                    LIMIT 1
                ) AS last_run_finished_at,
                (
                    SELECT error_message
                    FROM crawl_runs
                    WHERE site_id = :site_id_sub_4
                    ORDER BY id DESC
                    LIMIT 1
                ) AS last_run_error
            FROM crawl_runs
            WHERE site_id = :site_id
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':site_id_sub_1', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':site_id_sub_2', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':site_id_sub_3', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':site_id_sub_4', $siteId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return [
                'runs_total' => 0,
                'runs_completed' => 0,
                'runs_failed' => 0,
                'pages_total' => 0,
                'pages_with_matches' => 0,
                'last_run_status' => null,
                'last_run_started_at' => null,
                'last_run_finished_at' => null,
                'last_run_error' => null,
            ];
        }

        return $row;
    }
}
