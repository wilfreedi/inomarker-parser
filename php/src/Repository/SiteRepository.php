<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class SiteRepository
{
    private const PROGRESS_RECENT_URLS_LIMIT = 25;
    private const PROGRESS_LOG_LIMIT = 2000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sites ORDER BY created_at DESC');

        return $stmt->fetchAll();
    }

    public function create(string $name, string $baseUrl): void
    {
        $normalizedBaseUrl = $this->normalizeBaseUrl($baseUrl);
        $existing = $this->findByBaseUrl($normalizedBaseUrl);
        if ($existing !== null) {
            throw new \InvalidArgumentException('Сайт с таким URL уже существует');
        }

        $sql = <<<SQL
        INSERT INTO sites (name, base_url, is_enabled, status, created_at, updated_at)
        VALUES (:name, :base_url, 1, 'idle', :created_at, :updated_at)
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $now = $this->now();

        $stmt->execute([
            ':name' => trim($name),
            ':base_url' => $normalizedBaseUrl,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function update(int $siteId, string $name, string $baseUrl): void
    {
        $normalizedBaseUrl = $this->normalizeBaseUrl($baseUrl);
        $existing = $this->findByBaseUrl($normalizedBaseUrl);
        if ($existing !== null && (int) $existing['id'] !== $siteId) {
            throw new \InvalidArgumentException('Сайт с таким URL уже существует');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET name = :name,
                 base_url = :base_url,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':name' => trim($name),
            ':base_url' => $normalizedBaseUrl,
            ':updated_at' => $this->now(),
            ':id' => $siteId,
        ]);
    }

    public function delete(int $siteId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM findings WHERE site_id = :site_id')
                ->execute([':site_id' => $siteId]);
            $this->pdo->prepare('DELETE FROM pages WHERE site_id = :site_id')
                ->execute([':site_id' => $siteId]);
            $this->pdo->prepare('DELETE FROM crawl_runs WHERE site_id = :site_id')
                ->execute([':site_id' => $siteId]);
            $this->pdo->prepare('DELETE FROM sites WHERE id = :id')
                ->execute([':id' => $siteId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function resetForRecrawl(int $siteId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM findings WHERE site_id = :site_id')
                ->execute([':site_id' => $siteId]);
            $this->pdo->prepare('DELETE FROM pages WHERE site_id = :site_id')
                ->execute([':site_id' => $siteId]);
            $this->pdo->prepare('DELETE FROM crawl_runs WHERE site_id = :site_id')
                ->execute([':site_id' => $siteId]);

            $now = $this->now();
            $this->pdo->prepare(
                "UPDATE sites
                 SET is_enabled = 1,
                     status = 'idle',
                     scan_requested_at = :scan_requested_at,
                     last_crawled_at = NULL,
                     last_error = NULL,
                     progress_pages = 0,
                     progress_current_url = NULL,
                     progress_recent_urls = NULL,
                     progress_log = NULL,
                     progress_updated_at = NULL,
                     updated_at = :updated_at
                 WHERE id = :id"
            )->execute([
                ':scan_requested_at' => $now,
                ':updated_at' => $now,
                ':id' => $siteId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function requestScan(int $siteId): void
    {
        $sql = <<<SQL
        UPDATE sites
        SET is_enabled = 1,
            status = CASE
                WHEN status = 'running' THEN status
                ELSE 'idle'
            END,
            scan_requested_at = :scan_requested_at,
            progress_pages = 0,
            progress_current_url = NULL,
            progress_recent_urls = NULL,
            progress_log = NULL,
            progress_updated_at = NULL,
            updated_at = :updated_at
        WHERE id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $now = $this->now();
        $stmt->execute([
            ':scan_requested_at' => $now,
            ':updated_at' => $now,
            ':id' => $siteId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE id = :id');
        $stmt->execute([':id' => $siteId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function claimForScan(int $limit, int $scanIntervalMinutes): array
    {
        $limit = max(1, $limit);
        $scanIntervalMinutes = max(1, $scanIntervalMinutes);
        $dueBefore = gmdate('Y-m-d H:i:s', time() - ($scanIntervalMinutes * 60));

        $this->pdo->beginTransaction();
        try {
            $query = <<<SQL
            SELECT * FROM sites
            WHERE is_enabled = 1
              AND status IN ('idle', 'failed')
              AND (
                scan_requested_at IS NOT NULL
                OR last_crawled_at IS NULL
                OR last_crawled_at <= :due_before
              )
            ORDER BY
                CASE WHEN scan_requested_at IS NULL THEN 1 ELSE 0 END,
                scan_requested_at ASC,
                last_crawled_at ASC
            LIMIT :batch_limit
            SQL;
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':due_before', $dueBefore);
            $stmt->bindValue(':batch_limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $sites = $stmt->fetchAll();

            if ($sites !== []) {
                $ids = array_map(static fn (array $site): int => (int) $site['id'], $sites);
                $now = $this->now();
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE sites
                     SET status = 'running',
                         scan_requested_at = NULL,
                         last_error = NULL,
                         progress_pages = 0,
                         progress_current_url = NULL,
                         progress_recent_urls = NULL,
                         progress_log = NULL,
                         progress_updated_at = NULL,
                         updated_at = ?
                     WHERE id IN ({$placeholders})"
                );
                $update->bindValue(1, $now);
                foreach ($ids as $index => $id) {
                    $update->bindValue($index + 2, $id, PDO::PARAM_INT);
                }
                $update->execute();
            }

            $this->pdo->commit();

            return $sites;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markCompleted(int $siteId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sites
                 SET status = CASE
                    WHEN is_enabled = 0 THEN 'paused'
                    ELSE 'idle'
                 END,
                 last_crawled_at = :last_crawled_at,
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_updated_at = NULL,
                 updated_at = :updated_at,
                 last_error = NULL
             WHERE id = :id"
        );
        $now = $this->now();
        $stmt->execute([
            ':last_crawled_at' => $now,
            ':updated_at' => $now,
            ':id' => $siteId,
        ]);
    }

    public function markFailed(int $siteId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET status = 'paused',
                 is_enabled = 0,
                 last_error = :last_error,
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_updated_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':last_error' => mb_substr($error, 0, 1000),
            ':updated_at' => $this->now(),
            ':id' => $siteId,
        ]);
    }

    public function pause(int $siteId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET is_enabled = 0,
                 status = CASE
                    WHEN status = 'running' THEN 'cancel_requested'
                    ELSE 'paused'
                 END,
                 scan_requested_at = NULL,
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_log = NULL,
                 progress_updated_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':updated_at' => $this->now(),
            ':id' => $siteId,
        ]);
    }

    public function resume(int $siteId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET is_enabled = 1,
                 status = 'idle',
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_log = NULL,
                 progress_updated_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':updated_at' => $this->now(),
            ':id' => $siteId,
        ]);
    }

    public function cancelScan(int $siteId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET scan_requested_at = NULL,
                 status = CASE
                    WHEN status = 'running' THEN 'cancel_requested'
                    WHEN is_enabled = 0 THEN 'paused'
                    ELSE 'idle'
                 END,
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_log = NULL,
                 progress_updated_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':updated_at' => $this->now(),
            ':id' => $siteId,
        ]);
    }

    /** @param array<int, int> $siteIds */
    public function pauseManyWithError(array $siteIds, string $error): void
    {
        if ($siteIds === []) {
            return;
        }

        $siteIds = array_values(array_unique(array_map(static fn (int $id): int => max(0, $id), $siteIds)));
        $siteIds = array_values(array_filter($siteIds, static fn (int $id): bool => $id > 0));
        if ($siteIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($siteIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET status = 'paused',
                 is_enabled = 0,
                 scan_requested_at = NULL,
                 last_error = ?,
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_updated_at = NULL,
                 updated_at = ?
             WHERE id IN ({$placeholders})"
        );
        $stmt->bindValue(1, mb_substr($error, 0, 1000));
        $stmt->bindValue(2, $this->now());
        foreach ($siteIds as $index => $siteId) {
            $stmt->bindValue($index + 3, $siteId, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    /** @param array<int, int> $siteIds */
    public function recoverManyFromStale(array $siteIds, string $error): void
    {
        if ($siteIds === []) {
            return;
        }

        $siteIds = array_values(array_unique(array_map(static fn (int $id): int => max(0, $id), $siteIds)));
        $siteIds = array_values(array_filter($siteIds, static fn (int $id): bool => $id > 0));
        if ($siteIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($siteIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET status = 'idle',
                 is_enabled = 1,
                 scan_requested_at = ?,
                 last_error = ?,
                 progress_pages = 0,
                 progress_current_url = NULL,
                 progress_recent_urls = NULL,
                 progress_log = NULL,
                 progress_updated_at = NULL,
                 updated_at = ?
             WHERE id IN ({$placeholders})"
        );
        $now = $this->now();
        $stmt->bindValue(1, $now);
        $stmt->bindValue(2, mb_substr($error, 0, 1000));
        $stmt->bindValue(3, $now);
        foreach ($siteIds as $index => $siteId) {
            $stmt->bindValue($index + 4, $siteId, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    public function updateProgress(
        int $siteId,
        int $pagesVisited,
        string $currentUrl,
        ?string $eventMessage = null,
        string $eventLevel = 'info',
    ): void
    {
        $recentUrls = $this->loadRecentProgressUrls($siteId);
        if ($currentUrl !== '') {
            $recentUrls = array_values(array_filter(
                $recentUrls,
                static fn (string $url): bool => $url !== $currentUrl
            ));
            array_unshift($recentUrls, $currentUrl);
            $recentUrls = array_slice($recentUrls, 0, self::PROGRESS_RECENT_URLS_LIMIT);
        }

        $preparedEvent = $this->prepareProgressLogEntry($eventMessage, $eventLevel);
        $progressLogPayload = null;
        if ($preparedEvent !== null) {
            $logs = $this->loadProgressLogs($siteId);
            $logs = $this->appendPreparedLogEntry($logs, $preparedEvent);
            $progressLogPayload = $logs === [] ? null : json_encode($logs, JSON_THROW_ON_ERROR);
        }

        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET progress_pages = :progress_pages,
                 progress_current_url = :progress_current_url,
                 progress_recent_urls = :progress_recent_urls,
                 progress_log = COALESCE(:progress_log, progress_log),
                 progress_updated_at = :progress_updated_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = 'running'"
        );
        $now = $this->now();
        $stmt->execute([
            ':progress_pages' => max(0, $pagesVisited),
            ':progress_current_url' => $currentUrl !== '' ? mb_substr($currentUrl, 0, 1000) : null,
            ':progress_recent_urls' => $recentUrls === [] ? null : json_encode($recentUrls, JSON_THROW_ON_ERROR),
            ':progress_log' => $progressLogPayload,
            ':progress_updated_at' => $now,
            ':updated_at' => $now,
            ':id' => $siteId,
        ]);
    }

    public function appendProgressLog(
        int $siteId,
        string $message,
        string $level = 'info',
        bool $onlyWhenRunning = true,
    ): void {
        $preparedEvent = $this->prepareProgressLogEntry($message, $level);
        if ($preparedEvent === null) {
            return;
        }

        $site = $this->findById($siteId);
        if ($site === null) {
            return;
        }
        if ($onlyWhenRunning && (string) ($site['status'] ?? '') !== 'running') {
            return;
        }

        $logs = $this->decodeProgressLogs((string) ($site['progress_log'] ?? ''));
        $logs = $this->appendPreparedLogEntry($logs, $preparedEvent);

        $stmt = $this->pdo->prepare(
            "UPDATE sites
             SET progress_log = :progress_log,
                 progress_updated_at = :progress_updated_at,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $now = $this->now();
        $stmt->execute([
            ':progress_log' => $logs === [] ? null : json_encode($logs, JSON_THROW_ON_ERROR),
            ':progress_updated_at' => $now,
            ':updated_at' => $now,
            ':id' => $siteId,
        ]);
    }

    /** @return array<int, string> */
    public function recentProgressUrls(int $siteId, int $limit = 25): array
    {
        $limit = max(1, $limit);
        return array_slice($this->loadRecentProgressUrls($siteId), 0, $limit);
    }

    /** @return array<int, array{at:string,level:string,message:string}> */
    public function progressLogs(int $siteId, int $limit = self::PROGRESS_LOG_LIMIT): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare('SELECT progress_log FROM sites WHERE id = :id');
        $stmt->execute([':id' => $siteId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $logs = $this->decodeProgressLogs($raw);
        if ($logs === []) {
            return [];
        }

        return array_slice($logs, -$limit);
    }

    private function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Base URL is required');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $url = rtrim($url, '/');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL');
        }

        return $url;
    }

    /** @return array<string, mixed>|null */
    private function findByBaseUrl(string $baseUrl): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE base_url = :base_url');
        $stmt->execute([':base_url' => $baseUrl]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @return array<int, string> */
    private function loadRecentProgressUrls(int $siteId): array
    {
        $stmt = $this->pdo->prepare('SELECT progress_recent_urls FROM sites WHERE id = :id');
        $stmt->execute([':id' => $siteId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $urls = [];
        foreach ($decoded as $item) {
            $url = trim((string) $item);
            if ($url === '') {
                continue;
            }
            $urls[] = mb_substr($url, 0, 1000);
        }

        return array_values(array_unique($urls));
    }

    /** @return array<int, array{at:string,level:string,message:string}> */
    private function loadProgressLogs(int $siteId): array
    {
        $stmt = $this->pdo->prepare('SELECT progress_log FROM sites WHERE id = :id');
        $stmt->execute([':id' => $siteId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        return $this->decodeProgressLogs($raw);
    }

    /**
     * @param array<int, array{at:string,level:string,message:string}> $logs
     * @param array{at:string,level:string,message:string} $entry
     * @return array<int, array{at:string,level:string,message:string}>
     */
    private function appendPreparedLogEntry(array $logs, array $entry): array
    {
        $last = $logs !== [] ? $logs[array_key_last($logs)] : null;
        if (
            is_array($last)
            && ($last['message'] ?? '') === $entry['message']
            && ($last['level'] ?? 'info') === $entry['level']
        ) {
            return $logs;
        }

        $logs[] = $entry;
        if (count($logs) > self::PROGRESS_LOG_LIMIT) {
            $logs = array_slice($logs, -self::PROGRESS_LOG_LIMIT);
        }

        return array_values($logs);
    }

    /** @return array{at:string,level:string,message:string}|null */
    private function prepareProgressLogEntry(?string $message, string $level): ?array
    {
        $cleanMessage = trim((string) $message);
        if ($cleanMessage === '') {
            return null;
        }

        $normalizedLevel = $this->normalizeLogLevel($level);
        return [
            'at' => $this->now(),
            'level' => $normalizedLevel,
            'message' => mb_substr($cleanMessage, 0, 1000),
        ];
    }

    /** @return array<int, array{at:string,level:string,message:string}> */
    private function decodeProgressLogs(string $raw): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $logs = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $message = trim((string) ($item['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $at = trim((string) ($item['at'] ?? ''));
            if ($at === '') {
                $at = $this->now();
            }
            $logs[] = [
                'at' => mb_substr($at, 0, 32),
                'level' => $this->normalizeLogLevel((string) ($item['level'] ?? 'info')),
                'message' => mb_substr($message, 0, 1000),
            ];
        }

        if ($logs === []) {
            return [];
        }

        if (count($logs) > self::PROGRESS_LOG_LIMIT) {
            $logs = array_slice($logs, -self::PROGRESS_LOG_LIMIT);
        }

        return array_values($logs);
    }

    private function normalizeLogLevel(string $level): string
    {
        $level = trim(mb_strtolower($level));
        if (!in_array($level, ['info', 'warn', 'error', 'debug'], true)) {
            return 'info';
        }

        return $level;
    }
}
