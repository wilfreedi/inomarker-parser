<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class FindingRevalidationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findBySiteAndPatternSource(int $siteId, string $patternSource): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finding_revalidations WHERE site_id = :site_id AND pattern_source = :pattern_source LIMIT 1'
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern_source', $this->normalizePatternSource($patternSource), PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function markQueued(int $siteId, string $patternSource, int $total): void
    {
        $this->upsert($siteId, $patternSource, [
            'status' => 'queued',
            'total_findings' => max(0, $total),
            'checked_findings' => 0,
            'deleted_findings' => 0,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function markRunning(int $siteId, string $patternSource, int $total): void
    {
        $startedAt = gmdate('c');
        $this->upsert($siteId, $patternSource, [
            'status' => 'running',
            'total_findings' => max(0, $total),
            'checked_findings' => 0,
            'deleted_findings' => 0,
            'error_message' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
        ]);
    }

    public function updateProgress(int $siteId, string $patternSource, int $checked, int $deleted): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE finding_revalidations
             SET checked_findings = :checked_findings,
                 deleted_findings = :deleted_findings,
                 updated_at = :updated_at
             WHERE site_id = :site_id AND pattern_source = :pattern_source'
        );
        $stmt->execute([
            ':checked_findings' => max(0, $checked),
            ':deleted_findings' => max(0, $deleted),
            ':updated_at' => gmdate('c'),
            ':site_id' => $siteId,
            ':pattern_source' => $this->normalizePatternSource($patternSource),
        ]);
    }

    public function markCompleted(int $siteId, string $patternSource, int $checked, int $deleted): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE finding_revalidations
             SET status = :status,
                 checked_findings = :checked_findings,
                 deleted_findings = :deleted_findings,
                 error_message = NULL,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE site_id = :site_id AND pattern_source = :pattern_source'
        );
        $stmt->execute([
            ':status' => 'completed',
            ':checked_findings' => max(0, $checked),
            ':deleted_findings' => max(0, $deleted),
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':site_id' => $siteId,
            ':pattern_source' => $this->normalizePatternSource($patternSource),
        ]);
    }

    public function markFailed(int $siteId, string $patternSource, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE finding_revalidations
             SET status = :status,
                 error_message = :error_message,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE site_id = :site_id AND pattern_source = :pattern_source'
        );
        $now = gmdate('c');
        $stmt->execute([
            ':status' => 'failed',
            ':error_message' => mb_substr(trim($errorMessage), 0, 2000),
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':site_id' => $siteId,
            ':pattern_source' => $this->normalizePatternSource($patternSource),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function upsert(int $siteId, string $patternSource, array $values): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            <<<SQL
            INSERT INTO finding_revalidations (
                site_id, pattern_source, status, total_findings, checked_findings, deleted_findings,
                error_message, started_at, finished_at, updated_at
            ) VALUES (
                :site_id, :pattern_source, :status, :total_findings, :checked_findings, :deleted_findings,
                :error_message, :started_at, :finished_at, :updated_at
            )
            ON CONFLICT(site_id, pattern_source) DO UPDATE SET
                status = excluded.status,
                total_findings = excluded.total_findings,
                checked_findings = excluded.checked_findings,
                deleted_findings = excluded.deleted_findings,
                error_message = excluded.error_message,
                started_at = excluded.started_at,
                finished_at = excluded.finished_at,
                updated_at = excluded.updated_at
            SQL
        );
        $stmt->execute([
            ':site_id' => $siteId,
            ':pattern_source' => $this->normalizePatternSource($patternSource),
            ':status' => (string) ($values['status'] ?? 'queued'),
            ':total_findings' => max(0, (int) ($values['total_findings'] ?? 0)),
            ':checked_findings' => max(0, (int) ($values['checked_findings'] ?? 0)),
            ':deleted_findings' => max(0, (int) ($values['deleted_findings'] ?? 0)),
            ':error_message' => $values['error_message'],
            ':started_at' => $values['started_at'],
            ':finished_at' => $values['finished_at'],
            ':updated_at' => $now,
        ]);
    }

    private function normalizePatternSource(string $patternSource): string
    {
        $normalized = trim(mb_strtolower($patternSource));
        if (!in_array($normalized, ['full', 'short'], true)) {
            throw new \InvalidArgumentException("Unsupported pattern source: {$patternSource}");
        }

        return $normalized;
    }
}
