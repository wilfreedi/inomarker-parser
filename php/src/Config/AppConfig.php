<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\Env;

final class AppConfig
{
    /** @var array<string, string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(): self
    {
        $basePath = Env::get('APP_BASE_PATH', dirname(__DIR__, 2));
        $projectRoot = dirname($basePath);

        return new self([
            'app_env' => Env::get('APP_ENV', 'dev') ?? 'dev',
            'app_base_path' => $basePath,
            'db_path' => Env::get('DB_PATH', $basePath . '/storage/database.sqlite') ?? '',
            'regex_db_path' => Env::get('REGEX_DB_PATH', $projectRoot . '/db.json') ?? '',
            'regex_sync_endpoint' => Env::get('REGEX_SYNC_ENDPOINT', 'https://inomarker.ru/api/v1/plugin/regex-data') ?? '',
            'regex_sync_api_key' => Env::get('REGEX_SYNC_API_KEY', 'iw_auZA7dOvdzxlhyHZDJxSrtfCTKRvg6gUdUwyJvDi') ?? '',
            'admin_secret_password' => Env::get('ADMIN_SECRET_PASSWORD', 'лох') ?? 'лох',
            'crawler_endpoint' => Env::get('CRAWLER_ENDPOINT', 'http://crawler:3000/crawl') ?? '',
            'crawler_progress_endpoint' => Env::get('CRAWLER_PROGRESS_ENDPOINT', '') ?? '',
            'crawler_progress_token' => Env::get('CRAWLER_PROGRESS_TOKEN', '') ?? '',
            'worker_idle_sleep_seconds' => Env::get('WORKER_IDLE_SLEEP_SECONDS', '20') ?? '20',
        ]);
    }

    public function getString(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw new \InvalidArgumentException("Config key is missing: {$key}");
        }

        return $this->values[$key];
    }

    public function getInt(string $key): int
    {
        return (int) $this->getString($key);
    }
}
