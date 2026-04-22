<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CrawlRunRepository;
use App\Repository\FindingRepository;
use App\Repository\SiteRepository;

final class CrawlOrchestrator
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly CrawlRunRepository $crawlRunRepository,
        private readonly FindingRepository $findingRepository,
        private readonly CrawlerClient $crawlerClient,
        private readonly CrawledPageProcessor $crawledPageProcessor,
    ) {
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, int> $crawlerOptions
     * @return array{pages_total:int,pages_with_matches:int}
     */
    public function scanSite(array $site, array $crawlerOptions): array
    {
        $siteId = (int) $site['id'];
        $runId = $this->crawlRunRepository->start($siteId);
        $this->siteRepository->appendProgressLog($siteId, 'Запущен парсер: подготовка обхода сайта');
        $pagesTotal = 0;
        $pagesWithMatches = 0;
        $validPagesTotal = 0;
        $skippedMatchedPages = 0;

        try {
            $this->siteRepository->appendProgressLog($siteId, 'Отправлен запрос в crawler-сервис');
            $crawlResult = $this->crawlerClient->crawl((string) $site['base_url'], [
                ...$crawlerOptions,
                'site_id' => $siteId,
                'run_id' => $runId,
            ]);
            $pages = $crawlResult['pages'];
            $crawlStats = $crawlResult['stats'];
            $streamedPages = (bool) ($crawlResult['streamed_pages'] ?? false);
            $returnedPages = max(0, (int) ($crawlStats['returned'] ?? count($pages)));
            if ($returnedPages === 0 && $pages === []) {
                throw new \RuntimeException('Crawler returned no pages');
            }
            $this->siteRepository->appendProgressLog(
                $siteId,
                $streamedPages
                    ? sprintf('Crawler начал потоковую передачу страниц, ожидаем завершение обхода (%d страниц)', $returnedPages)
                    : sprintf('Crawler вернул %d страниц, запуск анализа совпадений', count($pages))
            );

            foreach ($pages as $page) {
                $pagesTotal++;
                $url = trim((string) ($page['url'] ?? ''));
                $status = isset($page['status']) ? (string) $page['status'] : 'n/a';
                $this->siteRepository->appendProgressLog(
                    $siteId,
                    sprintf('Анализ страницы #%d: %s (status=%s)', $pagesTotal, $url !== '' ? $url : '[empty_url]', $status),
                    'debug'
                );
                $result = $this->crawledPageProcessor->process($siteId, $runId, $page);
                if ($result['skipped_matched']) {
                    $skippedMatchedPages++;
                    $this->siteRepository->appendProgressLog(
                        $siteId,
                        sprintf('Страница уже помечена как matched, пропуск: %s', $url),
                        'debug'
                    );
                    continue;
                }
                if (!$result['processed']) {
                    $this->siteRepository->appendProgressLog(
                        $siteId,
                        sprintf('Страница пропущена как невалидная: #%d', $pagesTotal),
                        'warn'
                    );
                    continue;
                }
                $validPagesTotal++;
                $this->siteRepository->appendProgressLog(
                    $siteId,
                    sprintf('Страница сохранена: id=%d url=%s', (int) ($result['page_id'] ?? 0), $url),
                    'debug'
                );
                if ($result['has_matches']) {
                    $pagesWithMatches++;
                    $this->siteRepository->appendProgressLog(
                        $siteId,
                        sprintf('Совпадения найдены: url=%s', $url)
                    );
                } else {
                    $this->siteRepository->appendProgressLog(
                        $siteId,
                        sprintf('Совпадений не найдено: %s', $url),
                        'debug'
                    );
                }
            }

            if ($streamedPages) {
                $pagesTotal = $returnedPages;
                $pagesWithMatches = $this->findingRepository->countMatchedPagesByRun($runId);
                $validPagesTotal = $pagesTotal;
            }

            $this->siteRepository->appendProgressLog(
                $siteId,
                sprintf(
                    'Анализ завершен: валидных страниц %d, совпадений на страницах %d',
                    $validPagesTotal,
                    $pagesWithMatches
                )
            );

            if ($validPagesTotal === 0) {
                if ($skippedMatchedPages > 0) {
                    $this->crawlRunRepository->finish($runId, 'completed', $pagesTotal, 0, null);
                    $this->siteRepository->appendProgressLog(
                        $siteId,
                        sprintf('Запуск завершен: новых страниц нет, пропущено уже помеченных %d', $skippedMatchedPages)
                    );
                    $this->siteRepository->markCompleted($siteId);

                    return [
                        'pages_total' => $pagesTotal,
                        'pages_with_matches' => 0,
                    ];
                }
                throw new \RuntimeException('Crawler returned no valid pages');
            }

            $this->crawlRunRepository->finish($runId, 'completed', $pagesTotal, $pagesWithMatches, null);
            $this->siteRepository->appendProgressLog(
                $siteId,
                sprintf(
                    'Запуск завершен успешно: обработано %d страниц, страниц с совпадениями %d',
                    $pagesTotal,
                    $pagesWithMatches
                )
            );
            $this->siteRepository->markCompleted($siteId);

            return [
                'pages_total' => $pagesTotal,
                'pages_with_matches' => $pagesWithMatches,
            ];
        } catch (\Throwable $exception) {
            $this->crawlRunRepository->finish($runId, 'failed', $pagesTotal, $pagesWithMatches, $exception->getMessage());
            $this->siteRepository->appendProgressLog($siteId, 'Ошибка запуска: ' . $exception->getMessage(), 'error');
            $this->siteRepository->markFailed($siteId, $exception->getMessage() . ' | Сайт автоматически поставлен на паузу.');
            throw $exception;
        }
    }

    /** @param array<string, mixed> $page */
    public function ingestPage(int $siteId, int $runId, array $page): array
    {
        return $this->crawledPageProcessor->process($siteId, $runId, $page);
    }
}
