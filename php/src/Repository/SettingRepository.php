<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class SettingRepository
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'worker_batch_size' => '3',
        'worker_parallel_sites' => '1',
        'scan_interval_minutes' => '360',
        'worker_stale_run_minutes' => '5',
        'crawler_max_pages' => '10000',
        'crawler_max_depth' => '15',
        'crawler_timeout_ms' => '45000',
        'crawler_page_pause_ms' => '1500',
        'crawler_request_timeout_seconds' => '600',
        'crawler_retry_attempts' => '3',
        'crawler_retry_delay_ms' => '2500',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT key, value FROM settings');
        $stored = [];
        foreach ($stmt->fetchAll() as $row) {
            $stored[$row['key']] = $row['value'];
        }

        return $stored + self::DEFAULTS;
    }

    public function getInt(string $key): int
    {
        $all = $this->all();
        if (!array_key_exists($key, $all)) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }

        return max(1, (int) $all[$key]);
    }

    /** @param array<string, scalar> $settings */
    public function updateMany(array $settings): void
    {
        $sql = <<<SQL
        INSERT INTO settings (key, value, updated_at)
        VALUES (:key, :value, :updated_at)
        ON CONFLICT(key) DO UPDATE SET
            value = excluded.value,
            updated_at = excluded.updated_at
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $now = gmdate('c');

        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $stmt->execute([
                ':key' => $key,
                ':value' => (string) $value,
                ':updated_at' => $now,
            ]);
        }
    }
}
