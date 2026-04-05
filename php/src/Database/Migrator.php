<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $queries = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                base_url TEXT NOT NULL UNIQUE,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'idle',
                scan_requested_at TEXT NULL,
                last_crawled_at TEXT NULL,
                last_error TEXT NULL,
                progress_pages INTEGER NOT NULL DEFAULT 0,
                progress_current_url TEXT NULL,
                progress_recent_urls TEXT NULL,
                progress_log TEXT NULL,
                progress_updated_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS crawl_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                pages_total INTEGER NOT NULL DEFAULT 0,
                pages_with_matches INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
            )
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                title TEXT NULL,
                content TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                http_status INTEGER NULL,
                is_matched INTEGER NOT NULL DEFAULT 0,
                matched_entities TEXT NULL,
                crawled_at TEXT NOT NULL,
                UNIQUE(site_id, url),
                FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
            )
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS findings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                site_id INTEGER NOT NULL,
                page_id INTEGER NOT NULL,
                category TEXT NOT NULL,
                entity_name TEXT NOT NULL,
                pattern_source TEXT NOT NULL,
                matched_text TEXT NOT NULL,
                occurrences INTEGER NOT NULL DEFAULT 1,
                context_excerpt TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY(run_id) REFERENCES crawl_runs(id) ON DELETE CASCADE,
                FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
                FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_sites_status ON sites(status, is_enabled)',
            'CREATE INDEX IF NOT EXISTS idx_crawl_runs_site ON crawl_runs(site_id, started_at)',
            'CREATE INDEX IF NOT EXISTS idx_findings_site_run ON findings(site_id, run_id)',
            'CREATE INDEX IF NOT EXISTS idx_findings_site_source ON findings(site_id, pattern_source, id)',
            'CREATE INDEX IF NOT EXISTS idx_findings_entity ON findings(entity_name)',
        ];

        foreach ($queries as $query) {
            $this->pdo->exec($query);
        }

        $this->ensureColumn('pages', 'is_matched', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('pages', 'matched_entities', 'TEXT NULL');
        $this->ensureColumn('sites', 'progress_pages', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('sites', 'progress_current_url', 'TEXT NULL');
        $this->ensureColumn('sites', 'progress_recent_urls', 'TEXT NULL');
        $this->ensureColumn('sites', 'progress_log', 'TEXT NULL');
        $this->ensureColumn('sites', 'progress_updated_at', 'TEXT NULL');
        $this->pdo->exec(
            "UPDATE pages
             SET is_matched = 1
             WHERE is_matched = 0
               AND EXISTS (SELECT 1 FROM findings f WHERE f.page_id = pages.id)"
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_pages_site_matched ON pages(site_id, is_matched)');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        $columns = $stmt->fetchAll();
        foreach ($columns as $info) {
            if (($info['name'] ?? '') === $column) {
                return;
            }
        }

        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
