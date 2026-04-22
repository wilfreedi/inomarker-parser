<?php

declare(strict_types=1);

namespace App;

use App\Config\AppConfig;
use App\Controller\AdminController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repository\CrawlRunRepository;
use App\Repository\FindingRepository;
use App\Repository\FindingRevalidationRepository;
use App\Repository\PageRepository;
use App\Repository\RunRepository;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;
use App\Service\CrawlOrchestrator;
use App\Service\CrawledPageProcessor;
use App\Service\CrawlerClient;
use App\Service\DetachedConsoleLauncher;
use App\Service\FindingsRevalidator;
use App\Service\PatternCatalog;
use App\Service\RegexSyncService;
use App\Service\WorkerService;
use App\Service\SiteReportService;
use App\View\Renderer;
use PDO;

final class Application
{
    private PDO $pdo;
    private SiteRepository $siteRepository;
    private SettingRepository $settingRepository;
    private CrawlRunRepository $crawlRunRepository;
    private RunRepository $runRepository;
    private PageRepository $pageRepository;
    private FindingRepository $findingRepository;
    private FindingRevalidationRepository $findingRevalidationRepository;
    private PatternCatalog $patternCatalog;
    private RegexSyncService $regexSyncService;
    private FindingsRevalidator $findingsRevalidator;
    private DetachedConsoleLauncher $detachedConsoleLauncher;
    private CrawlerClient $crawlerClient;
    private CrawledPageProcessor $crawledPageProcessor;
    private CrawlOrchestrator $crawlOrchestrator;
    private SiteReportService $siteReportService;
    private Renderer $renderer;

    public function __construct(private readonly AppConfig $config)
    {
        $this->pdo = ConnectionFactory::createSqlite($this->config->getString('db_path'));
        (new Migrator($this->pdo))->migrate();

        $this->siteRepository = new SiteRepository($this->pdo);
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->crawlRunRepository = new CrawlRunRepository($this->pdo);
        $this->runRepository = new RunRepository($this->pdo);
        $this->pageRepository = new PageRepository($this->pdo);
        $this->findingRepository = new FindingRepository($this->pdo);
        $this->findingRevalidationRepository = new FindingRevalidationRepository($this->pdo);

        $this->patternCatalog = new PatternCatalog($this->config->getString('regex_db_path'));
        $this->regexSyncService = new RegexSyncService(
            $this->config->getString('regex_sync_endpoint'),
            $this->config->getString('regex_sync_api_key'),
            $this->config->getString('regex_db_path')
        );
        $this->findingsRevalidator = new FindingsRevalidator(
            $this->findingRepository,
            $this->pageRepository,
            $this->findingRevalidationRepository,
            $this->patternCatalog
        );
        $this->crawledPageProcessor = new CrawledPageProcessor(
            $this->pageRepository,
            $this->findingRepository,
            $this->patternCatalog
        );
        $this->detachedConsoleLauncher = new DetachedConsoleLauncher(
            $this->config->getString('app_base_path') . '/bin/console'
        );
        $this->crawlerClient = new CrawlerClient(
            $this->config->getString('crawler_endpoint'),
            null,
            $this->config->getString('crawler_progress_endpoint'),
            $this->config->getString('crawler_progress_token'),
            $this->config->getString('crawler_page_endpoint'),
            $this->config->getString('crawler_page_token')
        );
        $this->crawlOrchestrator = new CrawlOrchestrator(
            $this->siteRepository,
            $this->crawlRunRepository,
            $this->findingRepository,
            $this->crawlerClient,
            $this->crawledPageProcessor
        );
        $this->siteReportService = new SiteReportService(
            $this->siteRepository,
            $this->runRepository,
            $this->pageRepository,
            $this->findingRepository
        );
        $this->renderer = new Renderer($this->config->getString('app_base_path') . '/views');
    }

    public static function boot(): self
    {
        return new self(AppConfig::fromEnvironment());
    }

    public function adminController(): AdminController
    {
        return new AdminController(
            $this->siteRepository,
            $this->settingRepository,
            $this->findingRepository,
            $this->findingRevalidationRepository,
            $this->crawlRunRepository,
            $this->runRepository,
            $this->pageRepository,
            $this->siteReportService,
            $this->renderer,
            $this->regexSyncService,
            $this->detachedConsoleLauncher,
            $this->config->getString('regex_db_path'),
            $this->crawlOrchestrator,
            $this->config->getString('crawler_progress_token'),
            $this->config->getString('admin_secret_password')
        );
    }

    public function workerService(): WorkerService
    {
        return new WorkerService(
            $this->siteRepository,
            $this->crawlRunRepository,
            $this->settingRepository,
            $this->crawlOrchestrator,
            $this->config->getInt('worker_idle_sleep_seconds'),
        );
    }

    public function migrate(): void
    {
        (new Migrator($this->pdo))->migrate();
    }

    public function runScanForSite(int $siteId): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            throw new \RuntimeException("Site {$siteId} not found");
        }
        $settings = $this->settingRepository->all();
        $this->crawlOrchestrator->scanSite($site, [
            'max_pages' => (int) ($settings['crawler_max_pages'] ?? 10000),
            'max_depth' => (int) ($settings['crawler_max_depth'] ?? 15),
            'timeout_ms' => (int) ($settings['crawler_timeout_ms'] ?? 45000),
            'page_pause_ms' => (int) ($settings['crawler_page_pause_ms'] ?? 1500),
            'max_duration_seconds' => (int) ($settings['crawler_max_duration_seconds'] ?? 3600),
            'request_timeout_seconds' => (int) ($settings['crawler_request_timeout_seconds'] ?? 600),
            'retry_attempts' => (int) ($settings['crawler_retry_attempts'] ?? 3),
            'retry_delay_ms' => (int) ($settings['crawler_retry_delay_ms'] ?? 2500),
        ]);
    }

    public function revalidateFindingsForSite(int $siteId, string $patternSource): array
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            throw new \RuntimeException("Site {$siteId} not found");
        }

        try {
            return $this->findingsRevalidator->revalidateSite($siteId, $patternSource);
        } catch (\Throwable $exception) {
            $this->findingRevalidationRepository->markFailed($siteId, $patternSource, $exception->getMessage());
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function findingRevalidationStatus(int $siteId, string $patternSource): ?array
    {
        return $this->findingRevalidationRepository->findBySiteAndPatternSource($siteId, $patternSource);
    }
}
