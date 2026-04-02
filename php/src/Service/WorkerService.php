<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CrawlRunRepository;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;

final class WorkerService
{
    private const MAX_PARALLEL_SITES = 3;

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
            $parallelSites = min(self::MAX_PARALLEL_SITES, max(1, (int) ($settings['worker_parallel_sites'] ?? 1)));
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

            $claimLimit = max(1, min($batchSize, $parallelSites));
            $claimedSites = $this->siteRepository->claimForScan($claimLimit, $scanIntervalMinutes);
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

            if ($parallelSites > 1 && count($claimedSites) > 1) {
                $this->scanSitesInParallel($claimedSites, $parallelSites);
                if ($once) {
                    return;
                }
                continue;
            }

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

    /**
     * @param array<int, array<string, mixed>> $sites
     */
    private function scanSitesInParallel(array $sites, int $parallelSites): void
    {
        $limit = max(1, min(self::MAX_PARALLEL_SITES, $parallelSites));
        $queue = array_values($sites);
        /** @var array<int, array{process:resource,site_id:int,site_url:string}> $running */
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $limit) {
                $site = array_shift($queue);
                if (!is_array($site)) {
                    continue;
                }
                $siteId = (int) ($site['id'] ?? 0);
                if ($siteId <= 0) {
                    continue;
                }
                $siteUrl = (string) ($site['base_url'] ?? '');
                try {
                    $this->log(sprintf('Starting parallel scan for site %d (%s)', $siteId, $siteUrl));
                    $running[] = [
                        'process' => $this->startSiteScanProcess($siteId),
                        'site_id' => $siteId,
                        'site_url' => $siteUrl,
                    ];
                } catch (\Throwable $exception) {
                    $this->markSiteFailedIfStillRunning(
                        $siteId,
                        'Не удалось запустить параллельный scan:site процесс: ' . $exception->getMessage()
                    );
                    $this->log(sprintf(
                        'Site %d failed to start parallel scan: %s',
                        $siteId,
                        $exception->getMessage()
                    ));
                }
            }

            foreach ($running as $index => $item) {
                $status = proc_get_status($item['process']);
                if (($status['running'] ?? false) === true) {
                    continue;
                }

                $exitCode = proc_close($item['process']);
                if ($exitCode === 0) {
                    $this->log(sprintf('Site %d scanned successfully (%s)', $item['site_id'], $item['site_url']));
                } else {
                    $this->markSiteFailedIfStillRunning(
                        $item['site_id'],
                        sprintf('Параллельный scan:site завершился с кодом %d', $exitCode)
                    );
                    $this->log(sprintf(
                        'Site %d finished with non-zero exit code %d (%s)',
                        $item['site_id'],
                        $exitCode,
                        $item['site_url']
                    ));
                }
                unset($running[$index]);
            }

            if ($running !== []) {
                usleep(200_000);
            }
        }
    }

    /** @return resource */
    private function startSiteScanProcess(int $siteId)
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        $cmd = sprintf(
            '%s %s scan:site %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($consolePath),
            $siteId
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, dirname($consolePath));
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start scan process for site {$siteId}");
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        return $process;
    }

    private function markSiteFailedIfStillRunning(int $siteId, string $message): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            return;
        }
        if ((string) ($site['status'] ?? '') !== 'running') {
            return;
        }
        $this->siteRepository->markFailed($siteId, $message . ' | Сайт автоматически поставлен на паузу.');
    }
}
