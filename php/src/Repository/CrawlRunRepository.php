<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CrawlRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(int $siteId): int
    {
        $sql = <<<SQL
        INSERT INTO crawl_runs (site_id, status, started_at, pages_total, pages_with_matches)
        VALUES (:site_id, 'running', :started_at, 0, 0)
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':site_id' => $siteId,
            ':started_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $runId, string $status, int $pagesTotal, int $pagesWithMatches, ?string $errorMessage): void
    {
        $sql = <<<SQL
        UPDATE crawl_runs
        SET status = :status,
            finished_at = :finished_at,
            pages_total = :pages_total,
            pages_with_matches = :pages_with_matches,
            error_message = :error_message
        WHERE id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':finished_at' => gmdate('Y-m-d H:i:s'),
            ':pages_total' => $pagesTotal,
            ':pages_with_matches' => $pagesWithMatches,
            ':error_message' => $errorMessage,
            ':id' => $runId,
        ]);
    }

    /** @return array<int, int> */
    public function failStaleRunning(int $staleMinutes, string $errorMessage): array
    {
        $staleMinutes = max(1, $staleMinutes);
        $startedBefore = gmdate('Y-m-d H:i:s', time() - ($staleMinutes * 60));

        $staleStmt = $this->pdo->prepare(
            <<<SQL
            SELECT r.id, r.site_id
            FROM crawl_runs r
            INNER JOIN sites s ON s.id = r.site_id
            WHERE r.status = 'running'
              AND s.status = 'running'
              AND r.started_at <= :started_before
              AND COALESCE(s.progress_updated_at, r.started_at) <= :started_before
            SQL
        );
        $staleStmt->execute([':started_before' => $startedBefore]);
        $rows = $staleStmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $runIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $siteIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['site_id'], $rows)));
        $placeholders = implode(', ', array_fill(0, count($runIds), '?'));
        $update = $this->pdo->prepare(
            "UPDATE crawl_runs
             SET status = 'failed',
                 finished_at = ?,
                 error_message = ?
             WHERE status = 'running'
               AND id IN ({$placeholders})"
        );
        $update->bindValue(1, gmdate('Y-m-d H:i:s'));
        $update->bindValue(2, mb_substr($errorMessage, 0, 1000));
        foreach ($runIds as $index => $runId) {
            $update->bindValue($index + 3, $runId, PDO::PARAM_INT);
        }
        $update->execute();

        return $siteIds;
    }

    public function isRunningForSite(int $runId, int $siteId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM crawl_runs
             WHERE id = :id
               AND site_id = :site_id
               AND status = 'running'
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $runId,
            ':site_id' => $siteId,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
