<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FindingRepository;
use App\Repository\CrawlRunRepository;
use App\Repository\RunRepository;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;
use App\Repository\PageRepository;
use App\Service\SiteReportService;
use App\View\Renderer;

final class AdminController
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly SettingRepository $settingRepository,
        private readonly FindingRepository $findingRepository,
        private readonly CrawlRunRepository $crawlRunRepository,
        private readonly RunRepository $runRepository,
        private readonly PageRepository $pageRepository,
        private readonly SiteReportService $siteReportService,
        private readonly Renderer $renderer,
        private readonly string $crawlerProgressToken = '',
        private readonly string $adminSecretPassword = 'лох',
    ) {
    }

    public function dashboard(?string $notice, ?string $error): string
    {
        $sites = $this->siteRepository->all();
        $runs = $this->runRepository->recent(25);
        $findings = $this->findingRepository->recent(100);

        $content = $this->renderer->render('dashboard', [
            'runs' => $runs,
            'findings' => $findings,
            'stats' => [
                'sites_total' => count($sites),
                'sites_running' => count(array_filter($sites, static fn (array $site): bool => (string) $site['status'] === 'running')),
                'sites_paused' => count(array_filter($sites, static fn (array $site): bool => in_array((string) $site['status'], ['paused', 'cancel_requested'], true))),
                'runs_failed' => count(array_filter($runs, static fn (array $run): bool => (string) $run['status'] === 'failed')),
                'findings_total' => count($findings),
            ],
        ]);

        return $this->renderLayout('Dashboard', $content, '/', $notice, $error);
    }

    public function sites(?string $notice, ?string $error): string
    {
        $content = $this->renderer->render('sites/index', [
            'sites' => $this->siteRepository->all(),
        ]);

        return $this->renderLayout('Sites', $content, '/sites', $notice, $error);
    }

    public function newSite(?string $notice, ?string $error): string
    {
        $content = $this->renderer->render('sites/new');

        return $this->renderLayout('New Site', $content, '/sites', $notice, $error);
    }

    public function editSite(int $siteId, ?string $notice, ?string $error): string
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $content = $this->renderer->render('sites/edit', [
            'site' => $site,
        ]);

        return $this->renderLayout('Edit Site', $content, '/sites', $notice, $error);
    }

    public function siteReport(
        int $siteId,
        ?string $notice,
        ?string $error,
        int $pagesPage = 1,
        int $findingsPage = 1
    ): string
    {
        $report = $this->siteReportService->build($siteId, max(1, $pagesPage), max(1, $findingsPage));
        if ($report === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $content = $this->renderer->render('sites/report', $report);

        return $this->renderLayout('Site Report', $content, '/sites', $notice, $error);
    }

    public function siteFindings(
        int $siteId,
        ?string $notice,
        ?string $error,
        int $fullFindingsPage = 1,
        int $shortFindingsPage = 1,
        string $activeReport = 'full'
    ): string
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }
        $normalizedActiveReport = $this->normalizeFindingsReport($activeReport);

        $content = $this->renderer->render('sites/findings', [
            'site' => $site,
            'reports' => [
                'full' => $this->buildFindingsReport($siteId, 'full', $fullFindingsPage),
                'short' => $this->buildFindingsReport($siteId, 'short', $shortFindingsPage),
            ],
            'activeReport' => $normalizedActiveReport,
        ]);

        return $this->renderLayout('Site Findings', $content, '/sites', $notice, $error);
    }

    public function settings(?string $notice, ?string $error): string
    {
        $content = $this->renderer->render('settings', [
            'settings' => $this->settingRepository->all(),
        ]);

        return $this->renderLayout('Settings', $content, '/settings', $notice, $error);
    }

    public function login(?string $notice, ?string $error, string $returnTo = '/'): string
    {
        $content = $this->renderer->render('auth/login', [
            'return_to' => $returnTo,
        ]);

        return $this->renderLayout('Login', $content, '', $notice, $error, false);
    }

    public function isValidAdminPassword(string $password): bool
    {
        $trimmed = trim($password);
        if ($trimmed === '' || $this->adminSecretPassword === '') {
            return false;
        }

        return hash_equals($this->adminSecretPassword, $trimmed);
    }

    /** @param array<string, mixed> $post */
    public function createSite(array $post): void
    {
        $returnPath = $this->resolveReturnPath($post, '/sites');

        try {
            $payload = $this->validatedSitePayload($post);
            $this->siteRepository->create($payload['name'], $payload['base_url']);
            $this->redirectWithMessage('/sites', 'Сайт добавлен', null);
        } catch (\Throwable $exception) {
            $this->redirectWithMessage($returnPath, null, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $post */
    public function updateSite(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        try {
            $payload = $this->validatedSitePayload($post);
            $this->siteRepository->update($siteId, $payload['name'], $payload['base_url']);
            $this->redirectWithMessage("/sites/{$siteId}/edit", 'Изменения сохранены', null);
        } catch (\Throwable $exception) {
            $this->redirectWithMessage("/sites/{$siteId}/edit", null, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $post */
    public function deleteSite(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $confirmation = trim((string) ($post['delete_confirmation'] ?? ''));
        if (!$this->matchesDeleteConfirmation($site, $confirmation)) {
            $this->redirectWithMessage(
                "/sites/{$siteId}/edit",
                null,
                'Для удаления введите точное имя сайта или его URL/домен в поле подтверждения'
            );
        }

        $this->siteRepository->delete($siteId);
        $this->redirectWithMessage('/sites', 'Сайт удален', null);
    }

    /** @param array<string, mixed> $post */
    public function requestScan(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $this->siteRepository->requestScan($siteId);
        $this->redirectWithMessage($this->resolveReturnPath($post, '/sites'), 'Сканирование поставлено в очередь', null);
    }

    /** @param array<string, mixed> $post */
    public function pauseSite(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $this->siteRepository->pause($siteId);
        $this->redirectWithMessage($this->resolveReturnPath($post, '/sites'), 'Сайт поставлен на паузу', null);
    }

    /** @param array<string, mixed> $post */
    public function resumeSite(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $this->siteRepository->resume($siteId);
        $this->redirectWithMessage($this->resolveReturnPath($post, '/sites'), 'Сайт возобновлен', null);
    }

    /** @param array<string, mixed> $post */
    public function cancelScan(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }

        $this->siteRepository->cancelScan($siteId);
        $this->redirectWithMessage($this->resolveReturnPath($post, '/sites'), 'Запрос на отмену отправлен', null);
    }

    /** @param array<string, mixed> $post */
    public function recrawlSite(int $siteId, array $post): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->redirectWithMessage('/sites', null, 'Сайт не найден');
        }
        if ((string) $site['status'] === 'running') {
            $this->redirectWithMessage($this->resolveReturnPath($post, '/sites'), null, 'Сайт уже сканируется');
        }

        $this->siteRepository->resetForRecrawl($siteId);
        $this->redirectWithMessage(
            $this->resolveReturnPath($post, '/sites'),
            'Переобход запущен: данные очищены, сайт поставлен в очередь',
            null
        );
    }

    public function deleteFindingApi(int $siteId, int $findingId): void
    {
        if ($siteId <= 0 || $findingId <= 0) {
            $this->respondJson(422, ['ok' => false, 'error' => 'invalid_ids']);
        }

        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->respondJson(404, ['ok' => false, 'error' => 'site_not_found']);
        }

        $finding = $this->findingRepository->findByIdAndSite($findingId, $siteId);
        if ($finding === null) {
            $this->respondJson(404, ['ok' => false, 'error' => 'finding_not_found']);
        }

        $deleted = $this->findingRepository->deleteByIdAndSite($findingId, $siteId);
        if (!$deleted) {
            $this->respondJson(409, ['ok' => false, 'error' => 'delete_conflict']);
        }

        $this->pageRepository->syncMatchedState((int) $finding['page_id']);
        $this->respondJson(200, [
            'ok' => true,
            'site_id' => $siteId,
            'finding_id' => $findingId,
        ]);
    }

    /** @param array<string, mixed> $server */
    public function ingestCrawlProgress(string $rawBody, array $server): void
    {
        if (!$this->isCrawlerProgressAuthorized($server)) {
            $this->respondJson(403, ['ok' => false, 'error' => 'forbidden']);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->respondJson(400, ['ok' => false, 'error' => 'invalid_json']);
        }

        if (!is_array($decoded)) {
            $this->respondJson(400, ['ok' => false, 'error' => 'invalid_payload']);
        }

        $siteId = (int) ($decoded['siteId'] ?? 0);
        $runId = (int) ($decoded['runId'] ?? 0);
        $pagesVisited = (int) ($decoded['pagesVisited'] ?? 0);
        $currentUrl = trim((string) ($decoded['currentUrl'] ?? ''));
        $eventMessage = trim((string) ($decoded['event'] ?? ''));
        $eventLevel = trim((string) ($decoded['eventLevel'] ?? 'info'));
        if ($siteId <= 0 || $runId <= 0) {
            $this->respondJson(422, ['ok' => false, 'error' => 'missing_ids']);
        }

        if (!$this->crawlRunRepository->isRunningForSite($runId, $siteId)) {
            $this->respondJson(202, ['ok' => true, 'ignored' => 'run_not_running']);
        }

        $this->siteRepository->updateProgress(
            $siteId,
            $pagesVisited,
            $currentUrl,
            $eventMessage !== '' ? $eventMessage : null,
            $eventLevel
        );
        $this->respondJson(200, ['ok' => true]);
    }

    public function siteLive(int $siteId, bool $withDetails = false): void
    {
        $site = $this->siteRepository->findById($siteId);
        if ($site === null) {
            $this->respondJson(404, ['ok' => false, 'error' => 'site_not_found']);
        }

        $basePayload = [
            'ok' => true,
            'site' => [
                'id' => (int) $site['id'],
                'status' => (string) ($site['status'] ?? 'idle'),
                'progress_pages' => (int) ($site['progress_pages'] ?? 0),
                'progress_current_url' => (string) ($site['progress_current_url'] ?? ''),
                'progress_updated_at' => (string) ($site['progress_updated_at'] ?? ''),
                'last_error' => (string) ($site['last_error'] ?? ''),
                'last_crawled_at' => (string) ($site['last_crawled_at'] ?? ''),
            ],
        ];
        if (!$withDetails) {
            $this->respondJson(200, $basePayload);
        }

        $recentPages = $this->pageRepository->recentBySite($siteId, 12);
        $liveRecentUrls = $this->siteRepository->recentProgressUrls($siteId, 12);
        $liveLogs = $this->siteRepository->progressLogs($siteId, 80);
        $this->respondJson(200, $basePayload + [
            'live_logs' => $liveLogs,
            'recent_pages' => array_map(
                static function (array $page): array {
                    $matchedEntities = [];
                    if (!empty($page['matched_entities']) && is_string($page['matched_entities'])) {
                        try {
                            /** @var mixed $decoded */
                            $decoded = json_decode($page['matched_entities'], true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                $matchedEntities = array_values(array_map(static fn ($item): string => (string) $item, $decoded));
                            }
                        } catch (\Throwable) {
                            $matchedEntities = [];
                        }
                    }

                    return [
                        'url' => (string) ($page['url'] ?? ''),
                        'title' => (string) ($page['title'] ?? ''),
                        'http_status' => isset($page['http_status']) ? (int) $page['http_status'] : null,
                        'is_matched' => (int) ($page['is_matched'] ?? 0),
                        'matched_entities' => $matchedEntities,
                        'crawled_at' => (string) ($page['crawled_at'] ?? ''),
                    ];
                },
                $recentPages
            ),
            'live_recent_urls' => $liveRecentUrls,
        ]);
    }

    /** @param array<string, mixed> $query */
    public function sitesLive(array $query): void
    {
        $rawIds = trim((string) ($query['ids'] ?? ''));
        if ($rawIds === '') {
            $this->respondJson(422, ['ok' => false, 'error' => 'missing_ids']);
        }

        $tokens = preg_split('/[,\s]+/', $rawIds) ?: [];
        $siteIds = [];
        foreach ($tokens as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $siteIds[] = $id;
            }
        }
        $siteIds = array_values(array_unique($siteIds));
        if ($siteIds === []) {
            $this->respondJson(422, ['ok' => false, 'error' => 'invalid_ids']);
        }
        $siteIds = array_slice($siteIds, 0, 200);

        $states = $this->siteRepository->liveStatesByIds($siteIds);
        $sites = [];
        foreach ($siteIds as $siteId) {
            if (isset($states[$siteId])) {
                $sites[] = $states[$siteId];
            }
        }

        $this->respondJson(200, [
            'ok' => true,
            'sites' => $sites,
        ]);
    }

    /** @param array<string, mixed> $post */
    public function updateSettings(array $post): void
    {
        $allowedKeys = [
            'worker_batch_size',
            'worker_parallel_sites',
            'scan_interval_minutes',
            'worker_stale_run_minutes',
            'crawler_max_pages',
            'crawler_max_depth',
            'crawler_timeout_ms',
            'crawler_page_pause_ms',
            'crawler_request_timeout_seconds',
            'crawler_retry_attempts',
            'crawler_retry_delay_ms',
        ];

        $settings = [];
        foreach ($allowedKeys as $key) {
            if (!isset($post[$key])) {
                continue;
            }
            $value = (int) $post[$key];
            if ($key === 'crawler_retry_delay_ms') {
                $settings[$key] = max(100, $value);
                continue;
            }
            if ($key === 'crawler_page_pause_ms') {
                $settings[$key] = max(0, $value);
                continue;
            }
            if ($key === 'crawler_request_timeout_seconds') {
                $settings[$key] = max(30, $value);
                continue;
            }
            if ($key === 'worker_parallel_sites') {
                $settings[$key] = min(3, max(1, $value));
                continue;
            }
            $settings[$key] = max(1, $value);
        }

        $this->settingRepository->updateMany($settings);
        $this->redirectWithMessage('/settings', 'Настройки сохранены', null);
    }

    /** @return array<string, mixed> */
    private function buildFindingsReport(int $siteId, string $patternSource, int $requestedPage): array
    {
        $perPage = 50;
        $totalItems = $this->findingRepository->countBySiteAndPatternSource($siteId, $patternSource);
        $pagination = $this->buildPagination($totalItems, $perPage, $requestedPage);

        return [
            'pattern_source' => $patternSource,
            'pattern_source_label' => mb_strtoupper($patternSource),
            'summary' => $this->findingRepository->summaryBySiteAndPatternSource($siteId, $patternSource),
            'top_entities' => $this->findingRepository->topEntitiesBySiteAndPatternSource($siteId, $patternSource, 20),
            'categories' => $this->findingRepository->categoriesBySiteAndPatternSource($siteId, $patternSource, 20),
            'findings' => $this->findingRepository->recentBySiteAndPatternSource(
                $siteId,
                $patternSource,
                $pagination['per_page'],
                ($pagination['current_page'] - 1) * $pagination['per_page']
            ),
            'pagination' => $pagination,
        ];
    }

    /** @return array<string, int|bool> */
    private function buildPagination(int $totalItems, int $perPage, int $requestedPage): array
    {
        $perPage = max(1, $perPage);
        $totalItems = max(0, $totalItems);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = max(1, min($totalPages, $requestedPage));
        $hasPrev = $currentPage > 1;
        $hasNext = $currentPage < $totalPages;

        return [
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'has_prev' => $hasPrev,
            'has_next' => $hasNext,
            'prev_page' => $hasPrev ? $currentPage - 1 : 1,
            'next_page' => $hasNext ? $currentPage + 1 : $totalPages,
            'start_page' => max(1, $currentPage - 2),
            'end_page' => min($totalPages, $currentPage + 2),
        ];
    }

    private function normalizeFindingsReport(string $activeReport): string
    {
        $normalized = trim(mb_strtolower($activeReport));

        return in_array($normalized, ['full', 'short'], true) ? $normalized : 'full';
    }

    /**
     * @param array<string, mixed> $post
     * @return array{name:string,base_url:string}
     */
    private function validatedSitePayload(array $post): array
    {
        $name = trim((string) ($post['name'] ?? ''));
        $baseUrl = trim((string) ($post['base_url'] ?? ''));
        if ($name === '' || $baseUrl === '') {
            throw new \InvalidArgumentException('Заполните название и URL');
        }

        return [
            'name' => $name,
            'base_url' => $baseUrl,
        ];
    }

    /** @param array<string, mixed> $post */
    private function resolveReturnPath(array $post, string $default): string
    {
        $candidate = trim((string) ($post['return_to'] ?? ''));
        if ($candidate === '') {
            return $default;
        }
        if (
            preg_match(
                '#^/$|^/settings$|^/sites$|^/sites/new$|^/sites/\d+$|^/sites/\d+/edit$|^/sites/\d+/findings(?:\?(?:(?:full_page|short_page|findings_page)=\d+|report=(?:full|short))(?:&(?:(?:full_page|short_page|findings_page)=\d+|report=(?:full|short)))*)?$#',
                $candidate
            ) === 1
        ) {
            return $candidate;
        }

        return $default;
    }

    /** @param array<string, mixed> $site */
    private function matchesDeleteConfirmation(array $site, string $confirmation): bool
    {
        $token = $this->normalizeDeleteConfirmation($confirmation);
        if ($token === '') {
            return false;
        }

        $candidates = [
            (string) ($site['name'] ?? ''),
            (string) ($site['base_url'] ?? ''),
        ];
        $host = parse_url((string) ($site['base_url'] ?? ''), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $candidates[] = $host;
        }

        foreach ($candidates as $candidate) {
            if ($token === $this->normalizeDeleteConfirmation($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDeleteConfirmation(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        $value = rtrim($value, '/');
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#^www\.#i', '', $value) ?? $value;

        return $value;
    }

    /** @param array<string, mixed> $server */
    private function isCrawlerProgressAuthorized(array $server): bool
    {
        if ($this->crawlerProgressToken === '') {
            return true;
        }
        $received = trim((string) ($server['HTTP_X_CRAWLER_PROGRESS_TOKEN'] ?? ''));
        if ($received === '') {
            return false;
        }

        return hash_equals($this->crawlerProgressToken, $received);
    }

    /** @param array<string, mixed> $payload */
    private function respondJson(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function renderLayout(
        string $title,
        string $content,
        string $activePath,
        ?string $notice,
        ?string $error,
        bool $isAuthenticated = true,
    ): string {
        return $this->renderer->render('layout', [
            'title' => $title . ' • Parser Inomarker',
            'content' => $content,
            'notice' => $notice,
            'error' => $error,
            'activePath' => $activePath,
            'isAuthenticated' => $isAuthenticated,
        ]);
    }

    private function redirectWithMessage(string $path, ?string $notice, ?string $error): void
    {
        $params = [];
        if ($notice !== null && $notice !== '') {
            $params['notice'] = $notice;
        }
        if ($error !== null && $error !== '') {
            $params['error'] = $error;
        }
        if ($params === []) {
            $this->redirect($path);
        }

        $separator = str_contains($path, '?') ? '&' : '?';
        $this->redirect($path . $separator . http_build_query($params));
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location, true, 302);
        exit;
    }
}
