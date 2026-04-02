<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CrawlRunRepository;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;

final class WorkerService
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly CrawlRunRepository $crawlRunRepository,
        private readonly SettingRepository $settingRepository,
        private readonly CrawlOrchestrator $crawlOrchestrator,
        private readonly int $idleSleepSeconds,
    ) {
    }

    public function run(bool $once = false): void
    {
        while (true) {
            $settings = $this->settingRepository->all();
            $batchSize = max(1, (int) ($settings['worker_batch_size'] ?? 2));
            $scanIntervalMinutes = max(1, (int) ($settings['scan_interval_minutes'] ?? 360));
            $configuredStaleRunMinutes = max(1, (int) ($settings['worker_stale_run_minutes'] ?? 5));
            $requestTimeoutSeconds = max(30, (int) ($settings['crawler_request_timeout_seconds'] ?? 300));
            $retryAttempts = max(1, (int) ($settings['crawler_retry_attempts'] ?? 2));
            $retryDelayMs = max(100, (int) ($settings['crawler_retry_delay_ms'] ?? 1500));
            $minStaleByCrawlerSeconds = ($requestTimeoutSeconds * $retryAttempts) + (($retryAttempts - 1) * (int) ceil($retryDelayMs / 1000)) + 60;
            $minStaleByCrawlerMinutes = max(1, (int) ceil($minStaleByCrawlerSeconds / 60));
            $staleRunMinutes = max($configuredStaleRunMinutes, $minStaleByCrawlerMinutes);
            $staleRunError = sprintf(
                'Зависший запуск автоматически завершен после %d минут ожидания. Сайт поставлен в очередь на повторный запуск.',
                $staleRunMinutes
            );
            $staleSiteIds = $this->crawlRunRepository->failStaleRunning($staleRunMinutes, $staleRunError);
            if ($staleSiteIds !== []) {
                $this->siteRepository->recoverManyFromStale($staleSiteIds, $staleRunError);
                $this->log(sprintf(
                    'Recovered %d stale running site(s): %s',
                    count($staleSiteIds),
                    implode(', ', array_map(static fn (int $id): string => (string) $id, $staleSiteIds))
                ));
            }

            $claimedSites = $this->siteRepository->claimForScan($batchSize, $scanIntervalMinutes);
            if ($claimedSites === []) {
                if ($once) {
                    return;
                }
                sleep($this->idleSleepSeconds);
                continue;
            }

            $crawlerOptions = [
                'max_pages' => max(1, (int) ($settings['crawler_max_pages'] ?? 5_000)),
                'max_depth' => max(1, (int) ($settings['crawler_max_depth'] ?? 10)),
                'timeout_ms' => max(5_000, (int) ($settings['crawler_timeout_ms'] ?? 30_000)),
                'page_pause_ms' => max(0, (int) ($settings['crawler_page_pause_ms'] ?? 1_000)),
                'request_timeout_seconds' => $requestTimeoutSeconds,
                'retry_attempts' => $retryAttempts,
                'retry_delay_ms' => $retryDelayMs,
            ];

            foreach ($claimedSites as $site) {
                $siteId = (int) $site['id'];
                try {
                    $this->log(sprintf(
                        'Starting scan for site %d (%s). Options: pages=%d depth=%d pageTimeoutMs=%d requestTimeoutSec=%d pauseMs=%d retries=%d',
                        $siteId,
                        (string) $site['base_url'],
                        $crawlerOptions['max_pages'],
                        $crawlerOptions['max_depth'],
                        $crawlerOptions['timeout_ms'],
                        $crawlerOptions['request_timeout_seconds'],
                        $crawlerOptions['page_pause_ms'],
                        $crawlerOptions['retry_attempts']
                    ));
                    $this->crawlOrchestrator->scanSite($site, $crawlerOptions);
                    $this->log(sprintf(
                        'Site %d scanned successfully (%s)',
                        $siteId,
                        $site['base_url']
                    ));
                } catch (\Throwable $exception) {
                    $this->log(sprintf(
                        'Site %d failed: %s',
                        $siteId,
                        $exception->getMessage()
                    ));
                }
            }

            if ($once) {
                return;
            }
        }
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, '[' . gmdate('c') . "] {$message}\n");
    }
}
