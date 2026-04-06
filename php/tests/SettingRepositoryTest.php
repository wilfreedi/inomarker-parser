<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;

final class SettingRepositoryTest extends TestCase
{
    public function testStoredSettingsOverrideDefaults(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $repository = new SettingRepository($pdo);
        $repository->updateMany([
            'crawler_max_pages' => 123,
            'crawler_page_pause_ms' => 250,
        ]);

        $all = $repository->all();
        self::assertSame('123', $all['crawler_max_pages']);
        self::assertSame('250', $all['crawler_page_pause_ms']);
        self::assertSame('15', $all['crawler_max_depth']);
        self::assertSame('1', $all['worker_parallel_sites']);
        self::assertSame('never', $all['regex_sync_status']);
        self::assertSame('', $all['regex_sync_last_attempt_at']);
        self::assertSame('', $all['regex_sync_last_error']);
        self::assertArrayNotHasKey('search_short_regex', $all);
    }
}
