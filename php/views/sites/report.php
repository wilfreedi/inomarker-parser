<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var array<string, mixed> $runs_summary */
/** @var array<string, mixed> $pages_summary */
/** @var array<string, mixed> $findings_summary */
/** @var array<int, array<string, mixed>> $recent_runs */
/** @var array<int, array<string, mixed>> $recent_pages */
/** @var array<int, array<string, mixed>> $top_entities */
/** @var array<int, array<string, mixed>> $recent_findings */
/** @var array<string, mixed> $pages_pagination */
/** @var array<string, mixed> $findings_pagination */
/** @var callable $renderComponent */

$siteId = (int) $site['id'];
$pagesPagination = is_array($pages_pagination ?? null) ? $pages_pagination : [
    'current_page' => 1,
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false,
    'prev_page' => 1,
    'next_page' => 1,
    'start_page' => 1,
    'end_page' => 1,
];
$findingsPagination = is_array($findings_pagination ?? null) ? $findings_pagination : [
    'current_page' => 1,
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false,
    'prev_page' => 1,
    'next_page' => 1,
    'start_page' => 1,
    'end_page' => 1,
];
$buildReportUrl = static function (int $targetSiteId, int $targetPagesPage, int $targetFindingsPage): string {
    $query = [];
    if ($targetPagesPage > 1) {
        $query['pages_page'] = $targetPagesPage;
    }
    if ($targetFindingsPage > 1) {
        $query['findings_page'] = $targetFindingsPage;
    }
    $queryString = $query === [] ? '' : ('?' . http_build_query($query));

    return "/sites/{$targetSiteId}{$queryString}";
};
$initialLiveLogs = [];
if (!empty($site['progress_log']) && is_string($site['progress_log'])) {
    try {
        /** @var mixed $decodedLogs */
        $decodedLogs = json_decode((string) $site['progress_log'], true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        $decodedLogs = [];
    }
    if (is_array($decodedLogs)) {
        if (count($decodedLogs) > 120) {
            $decodedLogs = array_slice($decodedLogs, -120);
        }
        foreach ($decodedLogs as $item) {
            if (!is_array($item)) {
                continue;
            }
            $message = trim((string) ($item['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $level = trim(mb_strtolower((string) ($item['level'] ?? 'info')));
            if (!in_array($level, ['info', 'warn', 'error', 'debug'], true)) {
                $level = 'info';
            }
            $initialLiveLogs[] = [
                'at' => trim((string) ($item['at'] ?? '')),
                'level' => $level,
                'message' => $message,
            ];
        }
    }
}
?>
<div class="grid">
    <section class="card full">
        <div class="page-head">
            <div>
                <h2 class="page-title">
                    Отчет по сайту: <?= htmlspecialchars((string) $site['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </h2>
                <p class="page-subtitle">
                    URL:
                    <a href="<?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                        <?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                    · Статус: <span id="live-status-badge"><?= $renderComponent('status_badge', ['status' => (string) $site['status']]) ?></span>
                </p>
                <div id="live-progress-box"><?= $renderComponent('site_progress', ['site' => $site]) ?></div>
            </div>
            <div class="page-actions">
                <a class="subtle-link" href="/sites">К списку сайтов</a>
                <a class="subtle-link" href="/sites/<?= (int) $site['id'] ?>/edit">Редактировать</a>
            </div>
        </div>
        <?= $renderComponent('site_actions', [
            'site' => $site,
            'returnTo' => "/sites/" . $siteId,
            'showEdit' => false,
        ]) ?>
        <p class="autorefresh-note">Данные статуса и последних страниц обновляются точечно через API, без перезагрузки страницы.</p>
    </section>

    <section class="card full">
        <h2>Живой лог обхода</h2>
        <div id="live-log-console" class="log-console mono" role="log" aria-live="polite" aria-relevant="additions text">
            <div id="live-log-body">
                <?php if ($initialLiveLogs === []): ?>
                    <div class="log-line"><span class="log-line-msg muted">Логи текущего запуска появятся здесь.</span></div>
                <?php else: ?>
                    <?php foreach ($initialLiveLogs as $log): ?>
                        <div class="log-line log-level-<?= htmlspecialchars((string) $log['level'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <span class="log-line-time"><?= htmlspecialchars((string) ($log['at'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span class="log-line-level">[<?= htmlspecialchars((string) mb_strtoupper((string) $log['level']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]</span>
                            <span class="log-line-msg"><?= htmlspecialchars((string) $log['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <p class="autorefresh-note">Лог обновляется в реальном времени и показывает текущие этапы работы робота.</p>
    </section>

    <section class="card third">
        <div class="metric">
            <span class="label">Запусков</span>
            <span class="value"><?= (int) ($runs_summary['runs_total'] ?? 0) ?></span>
        </div>
        <p class="muted">Успешно: <?= (int) ($runs_summary['runs_completed'] ?? 0) ?> · Ошибок: <?= (int) ($runs_summary['runs_failed'] ?? 0) ?></p>
    </section>
    <section class="card third">
        <div class="metric">
            <span class="label">Страниц в индексе</span>
            <span class="value"><?= (int) ($pages_summary['pages_indexed'] ?? 0) ?></span>
        </div>
        <p class="muted">
            С пометкой «найдено»: <?= (int) ($pages_summary['pages_matched'] ?? 0) ?><br>
            HTTP ошибок: <?= (int) ($pages_summary['pages_with_http_errors'] ?? 0) ?>
        </p>
    </section>
    <section class="card third">
        <div class="metric">
            <span class="label">Всего совпадений</span>
            <span class="value"><?= (int) ($findings_summary['occurrences_total'] ?? 0) ?></span>
        </div>
        <p class="muted">
            Записей: <?= (int) ($findings_summary['findings_total'] ?? 0) ?> ·
            Сущностей: <?= (int) ($findings_summary['entities_total'] ?? 0) ?>
        </p>
        <a class="subtle-link" href="/sites/<?= $siteId ?>/findings">Открыть страницу совпадений</a>
    </section>

    <section class="card half">
        <h2>Сводка последнего запуска</h2>
        <p class="muted">
            Статус: <?= $renderComponent('status_badge', ['status' => (string) ($runs_summary['last_run_status'] ?? 'idle')]) ?><br>
            Старт: <?= htmlspecialchars((string) ($runs_summary['last_run_started_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
            Финиш: <?= htmlspecialchars((string) ($runs_summary['last_run_finished_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
            Ошибка: <span class="mono"><?= htmlspecialchars((string) ($runs_summary['last_run_error'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </p>
    </section>

    <section class="card half">
        <h2>Топ сущностей</h2>
        <table>
            <thead>
            <tr>
                <th>Сущность</th>
                <th>Категория</th>
                <th>Записей</th>
                <th>Вхождений</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($top_entities as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $row['entity_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $row['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= (int) $row['finding_rows'] ?></td>
                    <td><?= (int) $row['total_occurrences'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($top_entities === []): ?>
                <tr><td colspan="4" class="muted">Совпадений пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>История запусков</h2>
        <table>
            <thead>
            <tr>
                <th>Run ID</th>
                <th>Статус</th>
                <th>Страниц</th>
                <th>С совпадениями</th>
                <th>Старт</th>
                <th>Финиш</th>
                <th>Ошибка</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_runs as $run): ?>
                <tr>
                    <td><?= (int) $run['id'] ?></td>
                    <td><?= $renderComponent('status_badge', ['status' => (string) $run['status']]) ?></td>
                    <td><?= (int) $run['pages_total'] ?></td>
                    <td><?= (int) $run['pages_with_matches'] ?></td>
                    <td><?= htmlspecialchars((string) $run['started_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($run['finished_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="mono"><?= htmlspecialchars((string) ($run['error_message'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recent_runs === []): ?>
                <tr><td colspan="7" class="muted">Запусков пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Последние страницы</h2>
        <table>
            <thead>
            <tr>
                <th>URL</th>
                <th>Title</th>
                <th>HTTP</th>
                <th>Найдено</th>
                <th>Кого нашли</th>
                <th>Обновлено</th>
            </tr>
            </thead>
            <tbody id="live-recent-pages-body">
            <?php foreach ($recent_pages as $page): ?>
                <?php
                $matchedEntities = '';
                if (!empty($page['matched_entities'])) {
                    $decoded = json_decode((string) $page['matched_entities'], true);
                    if (is_array($decoded)) {
                        $matchedEntities = implode(', ', array_map(static fn ($item): string => (string) $item, $decoded));
                    }
                }
                ?>
                <tr>
                    <td>
                        <a href="<?= htmlspecialchars((string) $page['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                            <?= htmlspecialchars((string) $page['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($page['http_status'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= (int) ($page['is_matched'] ?? 0) === 1 ? 'Да' : 'Нет' ?></td>
                    <td class="mono"><?= htmlspecialchars($matchedEntities !== '' ? $matchedEntities : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($page['crawled_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recent_pages === []): ?>
                <tr><td colspan="6" class="muted">Страницы пока не проиндексированы.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ((int) ($pagesPagination['total_pages'] ?? 1) > 1): ?>
            <div class="pagination">
                <?php if (!empty($pagesPagination['has_prev'])): ?>
                    <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl($siteId, (int) ($pagesPagination['prev_page'] ?? 1), (int) ($findingsPagination['current_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">← Назад</a>
                <?php endif; ?>
                <?php for ($page = (int) ($pagesPagination['start_page'] ?? 1); $page <= (int) ($pagesPagination['end_page'] ?? 1); $page++): ?>
                    <?php if ($page === (int) ($pagesPagination['current_page'] ?? 1)): ?>
                        <span class="page-link active"><?= $page ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= htmlspecialchars($buildReportUrl($siteId, $page, (int) ($findingsPagination['current_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= $page ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if (!empty($pagesPagination['has_next'])): ?>
                    <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl($siteId, (int) ($pagesPagination['next_page'] ?? 1), (int) ($findingsPagination['current_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Вперед →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card full">
        <h2>Последние совпадения</h2>
        <table>
            <thead>
            <tr>
                <th>Время</th>
                <th>Сущность</th>
                <th>Категория</th>
                <th>Страница</th>
                <th>Кол-во</th>
                <th>Фрагмент</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_findings as $finding): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $finding['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $finding['entity_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $finding['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                        <a href="<?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                            <?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </td>
                    <td><?= (int) $finding['occurrences'] ?></td>
                    <td class="mono"><?= htmlspecialchars((string) ($finding['context_excerpt'] ?: $finding['matched_text']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recent_findings === []): ?>
                <tr><td colspan="6" class="muted">Совпадений пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ((int) ($findingsPagination['total_pages'] ?? 1) > 1): ?>
            <div class="pagination">
                <?php if (!empty($findingsPagination['has_prev'])): ?>
                    <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl($siteId, (int) ($pagesPagination['current_page'] ?? 1), (int) ($findingsPagination['prev_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">← Назад</a>
                <?php endif; ?>
                <?php for ($page = (int) ($findingsPagination['start_page'] ?? 1); $page <= (int) ($findingsPagination['end_page'] ?? 1); $page++): ?>
                    <?php if ($page === (int) ($findingsPagination['current_page'] ?? 1)): ?>
                        <span class="page-link active"><?= $page ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= htmlspecialchars($buildReportUrl($siteId, (int) ($pagesPagination['current_page'] ?? 1), $page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= $page ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if (!empty($findingsPagination['has_next'])): ?>
                    <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl($siteId, (int) ($pagesPagination['current_page'] ?? 1), (int) ($findingsPagination['next_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Вперед →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<script>
(() => {
    const siteId = <?= $siteId ?>;
    const currentPagesPage = <?= (int) ($pagesPagination['current_page'] ?? 1) ?>;
    const endpoint = `/api/sites/${siteId}/live?details=1`;
    const statusNode = document.getElementById('live-status-badge');
    const progressNode = document.getElementById('live-progress-box');
    const recentPagesBody = document.getElementById('live-recent-pages-body');
    const logConsole = document.getElementById('live-log-console');
    const logBody = document.getElementById('live-log-body');
    let lastLogsFingerprint = '';
    let pollInFlight = false;
    if (!statusNode || !progressNode || !recentPagesBody) {
        return;
    }

    const statusLabels = {
        idle: 'Готов',
        running: 'Сканируется',
        failed: 'Ошибка',
        paused: 'Пауза',
        cancel_requested: 'Остановка'
    };

    const escapeHtml = (value) =>
        String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

    const renderStatus = (status) => {
        const label = statusLabels[status] || status;
        return `<span class="status-pill status-${escapeHtml(status)}">${escapeHtml(label)}</span>`;
    };

    const renderProgress = (site) => {
        if (site.status !== 'running') {
            return '<span class="muted">-</span>';
        }
        const currentUrl = String(site.progress_current_url || '');
        const updatedAt = String(site.progress_updated_at || '');
        const pages = Number(site.progress_pages || 0);
        const currentUrlHtml = currentUrl === ''
            ? ''
            : `<span class="progress-text">Текущая: <a class="subtle-link" href="${escapeHtml(currentUrl)}" target="_blank" rel="noreferrer">${escapeHtml(currentUrl)}</a></span>`;
        const updatedHtml = updatedAt === ''
            ? ''
            : `<span class="progress-text muted">Обновлено: ${escapeHtml(updatedAt)}</span>`;
        return [
            '<div class="progress-live">',
            '<span class="dot-live" aria-hidden="true"></span>',
            '<span class="progress-text">Робот обходит страницы</span>',
            `<span class="progress-text">Обработано: <strong>${pages}</strong></span>`,
            currentUrlHtml,
            updatedHtml,
            '</div>'
        ].join('');
    };

    const renderRecentPageRows = (pages) => {
        if (!Array.isArray(pages) || pages.length === 0) {
            return '';
        }
        return pages.map((page) => {
            const url = String(page.url || '');
            const title = String(page.title || '');
            const httpStatus = page.http_status === null ? '-' : String(page.http_status ?? '-');
            const isMatched = Number(page.is_matched || 0) === 1 ? 'Да' : 'Нет';
            const matchedEntities = Array.isArray(page.matched_entities) && page.matched_entities.length > 0
                ? page.matched_entities.join(', ')
                : '-';
            const crawledAt = String(page.crawled_at || '-');
            return [
                '<tr>',
                `<td><a href="${escapeHtml(url)}" target="_blank" rel="noreferrer">${escapeHtml(url)}</a></td>`,
                `<td>${escapeHtml(title)}</td>`,
                `<td>${escapeHtml(httpStatus)}</td>`,
                `<td>${isMatched}</td>`,
                `<td class="mono">${escapeHtml(matchedEntities)}</td>`,
                `<td>${escapeHtml(crawledAt)}</td>`,
                '</tr>'
            ].join('');
        }).join('');
    };

    const renderLiveUrlRows = (urls) => {
        if (!Array.isArray(urls) || urls.length === 0) {
            return '';
        }
        return urls.map((url) => {
            const value = String(url || '');
            return [
                '<tr>',
                `<td><a href="${escapeHtml(value)}" target="_blank" rel="noreferrer">${escapeHtml(value)}</a></td>`,
                '<td class="muted">В обходе...</td>',
                '<td>-</td>',
                '<td>-</td>',
                '<td class="mono">-</td>',
                '<td class="muted">Сейчас</td>',
                '</tr>'
            ].join('');
        }).join('');
    };

    const renderEmptyRows = () => '<tr><td colspan="6" class="muted">Страницы пока не проиндексированы.</td></tr>';

    const isNearBottom = (element) => {
        if (!element) {
            return false;
        }
        return element.scrollHeight - element.scrollTop - element.clientHeight < 20;
    };

    const renderLiveLogs = (logs) => {
        if (!Array.isArray(logs) || logs.length === 0) {
            return '<div class="log-line"><span class="log-line-msg muted">Логи текущего запуска появятся здесь.</span></div>';
        }
        return logs.map((entry) => {
            const at = String(entry?.at || '-');
            const levelRaw = String(entry?.level || 'info').toLowerCase();
            const level = ['info', 'warn', 'error', 'debug'].includes(levelRaw) ? levelRaw : 'info';
            const message = String(entry?.message || '');
            return [
                `<div class="log-line log-level-${escapeHtml(level)}">`,
                `<span class="log-line-time">${escapeHtml(at)}</span>`,
                `<span class="log-line-level">[${escapeHtml(level.toUpperCase())}]</span>`,
                `<span class="log-line-msg">${escapeHtml(message)}</span>`,
                '</div>'
            ].join('');
        }).join('');
    };

    const applyLiveState = (payload) => {
        const site = payload.site || {};
        statusNode.innerHTML = renderStatus(String(site.status || 'idle'));
        progressNode.innerHTML = renderProgress(site);
        if (logBody) {
            const logs = Array.isArray(payload.live_logs) ? payload.live_logs : [];
            const last = logs.length > 0 ? logs[logs.length - 1] : null;
            const fingerprint = `${logs.length}|${String(last?.at || '')}|${String(last?.level || '')}|${String(last?.message || '')}`;
            if (fingerprint !== lastLogsFingerprint) {
                const stickToBottom = isNearBottom(logConsole);
                logBody.innerHTML = renderLiveLogs(logs);
                if (logConsole && stickToBottom) {
                    logConsole.scrollTop = logConsole.scrollHeight;
                }
                lastLogsFingerprint = fingerprint;
            }
        }

        if (currentPagesPage !== 1) {
            return;
        }

        const recentPages = Array.isArray(payload.recent_pages) ? payload.recent_pages : [];
        if (recentPages.length > 0) {
            recentPagesBody.innerHTML = renderRecentPageRows(recentPages);
            return;
        }

        const liveRecentUrls = Array.isArray(payload.live_recent_urls) ? payload.live_recent_urls : [];
        if (String(site.status || '') === 'running' && liveRecentUrls.length > 0) {
            recentPagesBody.innerHTML = renderLiveUrlRows(liveRecentUrls);
            return;
        }

        recentPagesBody.innerHTML = renderEmptyRows();
    };

    let polling = null;
    const tick = async () => {
        if (document.hidden || pollInFlight) {
            return;
        }
        pollInFlight = true;
        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (!payload || payload.ok !== true) {
                return;
            }
            applyLiveState(payload);
            if ((payload.site?.status || '') !== 'running' && polling !== null) {
                clearInterval(polling);
                polling = null;
            }
        } catch (_) {
            // Keep last rendered state if polling failed.
        } finally {
            pollInFlight = false;
        }
    };

    void tick();
    polling = setInterval(tick, 5000);
})();
</script>
