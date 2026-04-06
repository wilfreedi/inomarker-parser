<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var array<string, array<string, mixed>> $reports */
/** @var array<string, array<string, mixed>> $revalidationStatuses */
/** @var callable $renderComponent */
/** @var string|null $activeReport */

$siteId = (int) $site['id'];
/** @var array<string, mixed> $fullReport */
$fullReport = is_array($reports['full'] ?? null) ? $reports['full'] : [];
/** @var array<string, mixed> $shortReport */
$shortReport = is_array($reports['short'] ?? null) ? $reports['short'] : [];
$fullPagination = is_array($fullReport['pagination'] ?? null) ? $fullReport['pagination'] : ['current_page' => 1];
$shortPagination = is_array($shortReport['pagination'] ?? null) ? $shortReport['pagination'] : ['current_page' => 1];
$currentFullPage = (int) ($fullPagination['current_page'] ?? 1);
$currentShortPage = (int) ($shortPagination['current_page'] ?? 1);
$activeReport = trim(mb_strtolower((string) ($activeReport ?? 'full')));
$activeReport = in_array($activeReport, ['full', 'short'], true) ? $activeReport : 'full';

$buildFindingsUrl = static function (
    int $targetSiteId,
    int $targetFullPage,
    int $targetShortPage,
    string $targetReport = 'full'
): string {
    $query = [];
    if ($targetFullPage > 1) {
        $query['full_page'] = $targetFullPage;
    }
    if ($targetShortPage > 1) {
        $query['short_page'] = $targetShortPage;
    }
    if ($targetReport === 'short') {
        $query['report'] = 'short';
    }
    if ($query === []) {
        return "/sites/{$targetSiteId}/findings";
    }

    return "/sites/{$targetSiteId}/findings?" . http_build_query($query);
};

$highlightFragment = static function (string $fragment, string $matchedText): string {
    if ($fragment === '') {
        return '';
    }

    $needle = trim($matchedText);
    if ($needle === '') {
        return htmlspecialchars($fragment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $needleLength = mb_strlen($needle);
    if ($needleLength === 0) {
        return htmlspecialchars($fragment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $fragmentLower = mb_strtolower($fragment);
    $needleLower = mb_strtolower($needle);

    $offset = 0;
    $hasMatch = false;
    $result = '';
    while (true) {
        $position = mb_strpos($fragmentLower, $needleLower, $offset);
        if ($position === false) {
            $tail = mb_substr($fragment, $offset);
            $result .= htmlspecialchars($tail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            break;
        }

        $hasMatch = true;
        $before = mb_substr($fragment, $offset, $position - $offset);
        $match = mb_substr($fragment, $position, $needleLength);
        $result .= htmlspecialchars($before, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $result .= '<mark class="match-highlight">' . htmlspecialchars($match, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</mark>';
        $offset = $position + $needleLength;
    }
    if (!$hasMatch) {
        return htmlspecialchars($fragment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return $result;
};

$reportTitles = [
    'full' => 'FULL регулярки',
    'short' => 'SHORT регулярки',
];
$reportDescriptions = [
    'full' => 'Точные совпадения по полным выражениям.',
    'short' => 'Поиск по кратким вариантам регулярных выражений.',
];
$statusLabels = [
    'never' => 'Не запускалась',
    'queued' => 'В очереди',
    'running' => 'Выполняется',
    'completed' => 'Завершена',
    'failed' => 'Ошибка',
];
?>
<style>
    .report-switch {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }
    .report-switch-label {
        margin: 0;
        font-size: 12px;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--muted);
    }
    .report-switch-option {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        padding: 7px 12px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #fff;
        cursor: pointer;
        font-size: 13px;
        font-weight: 700;
        user-select: none;
    }
    .report-switch-option.active {
        border-color: rgba(15, 98, 254, 0.35);
        background: #eff6ff;
        color: var(--brand);
    }
    .report-switch-option input[type="radio"] {
        width: auto;
        margin: 0;
        padding: 0;
        accent-color: var(--brand);
    }
    .report-switch-submit {
        padding: 8px 12px;
        font-size: 13px;
    }
    .match-highlight {
        background: #fef08a;
        color: #1f2937;
        border-radius: 4px;
        padding: 0 2px;
        font-weight: 700;
    }
    .finding-delete-button {
        background: #b91c1c;
        color: #fff;
        padding: 6px 10px;
        border-radius: 8px;
        border: 0;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .finding-delete-button[disabled] {
        opacity: 0.65;
        cursor: default;
    }
    .revalidation-status {
        border: 1px solid var(--line);
        border-radius: 10px;
        background: var(--panel-soft);
        padding: 12px 14px;
        margin-bottom: 12px;
    }
    .revalidation-status-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-top: 10px;
    }
    .revalidation-status-item {
        font-size: 13px;
    }
    .revalidation-status-label {
        display: block;
        color: var(--muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 4px;
    }
    .revalidation-status-value {
        font-weight: 700;
    }
    .revalidation-status-error {
        margin-top: 8px;
        color: var(--danger);
        font-size: 13px;
    }
    @media (max-width: 900px) {
        .revalidation-status-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>
<div class="grid">
    <section class="card full">
        <div class="page-head">
            <div>
                <h2 class="page-title">
                    Совпадения по сайту: <?= htmlspecialchars((string) $site['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </h2>
                <p class="page-subtitle">
                    URL:
                    <a href="<?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                        <?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                    · Статус: <?= $renderComponent('status_badge', ['status' => (string) $site['status']]) ?>
                </p>
            </div>
            <div class="page-actions">
                <a class="subtle-link" href="/sites/<?= $siteId ?>">К отчету</a>
                <a class="subtle-link" href="/sites/<?= $siteId ?>/edit">Редактировать</a>
            </div>
        </div>

        <form
            method="get"
            action="/sites/<?= $siteId ?>/findings"
            class="report-switch"
            data-report-switch-form
        >
            <?php if ($currentFullPage > 1): ?>
                <input type="hidden" name="full_page" value="<?= $currentFullPage ?>">
            <?php endif; ?>
            <?php if ($currentShortPage > 1): ?>
                <input type="hidden" name="short_page" value="<?= $currentShortPage ?>">
            <?php endif; ?>

            <p class="report-switch-label">Отчет</p>
            <label class="report-switch-option <?= $activeReport === 'full' ? 'active' : '' ?>">
                <input type="radio" name="report" value="full" <?= $activeReport === 'full' ? 'checked' : '' ?>>
                <span>FULL</span>
            </label>
            <label class="report-switch-option <?= $activeReport === 'short' ? 'active' : '' ?>">
                <input type="radio" name="report" value="short" <?= $activeReport === 'short' ? 'checked' : '' ?>>
                <span>SHORT</span>
            </label>
            <noscript>
                <button type="submit" class="report-switch-submit">Показать</button>
            </noscript>
        </form>

        <?= $renderComponent('site_actions', [
            'site' => $site,
            'returnTo' => $buildFindingsUrl($siteId, $currentFullPage, $currentShortPage, $activeReport),
            'showEdit' => false,
        ]) ?>
    </section>

    <?php foreach ([$activeReport] as $reportKey): ?>
        <?php
        $report = $reportKey === 'full' ? $fullReport : $shortReport;
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $topEntities = is_array($report['top_entities'] ?? null) ? $report['top_entities'] : [];
        $categories = is_array($report['categories'] ?? null) ? $report['categories'] : [];
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $revalidationStatus = is_array($revalidationStatuses[$reportKey] ?? null) ? $revalidationStatuses[$reportKey] : [];
        $pagination = is_array($report['pagination'] ?? null) ? $report['pagination'] : [
            'current_page' => 1,
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
            'prev_page' => 1,
            'next_page' => 1,
            'start_page' => 1,
            'end_page' => 1,
        ];
        $buildReportUrl = static function (int $targetPage) use (
            $buildFindingsUrl,
            $siteId,
            $currentFullPage,
            $currentShortPage,
            $reportKey
        ): string {
            if ($reportKey === 'full') {
                return $buildFindingsUrl($siteId, $targetPage, $currentShortPage, 'full');
            }

            return $buildFindingsUrl($siteId, $currentFullPage, $targetPage, 'short');
        };
        $isPaginatedView = (int) ($pagination['current_page'] ?? 1) > 1;
        $isRevalidationBusy = in_array((string) ($revalidationStatus['status'] ?? 'never'), ['queued', 'running'], true);
        ?>

        <?php if (!$isPaginatedView): ?>
            <section class="card full">
                <h2>Отчет: <?= htmlspecialchars((string) ($reportTitles[$reportKey] ?? $reportKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <p class="muted">
                    <?= htmlspecialchars((string) ($reportDescriptions[$reportKey] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    Текущая страница: <?= (int) ($pagination['current_page'] ?? 1) ?>
                    · Всего страниц: <?= (int) ($pagination['total_pages'] ?? 1) ?>
                </p>
            </section>

            <section class="card third">
                <div class="metric">
                    <span class="label">Всего совпадений</span>
                    <span class="value"><?= (int) ($summary['occurrences_total'] ?? 0) ?></span>
                </div>
                <p class="muted">Записей: <?= (int) ($summary['findings_total'] ?? 0) ?></p>
            </section>
            <section class="card third">
                <div class="metric">
                    <span class="label">Уникальных сущностей</span>
                    <span class="value"><?= (int) ($summary['entities_total'] ?? 0) ?></span>
                </div>
            </section>
            <section class="card third">
                <div class="metric">
                    <span class="label">Категорий</span>
                    <span class="value"><?= (int) ($summary['categories_total'] ?? 0) ?></span>
                </div>
                <p class="muted">Тип: <?= htmlspecialchars((string) ($report['pattern_source_label'] ?? mb_strtoupper($reportKey)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
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
                    <?php foreach ($topEntities as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['entity_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $row['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= (int) $row['finding_rows'] ?></td>
                            <td><?= (int) $row['total_occurrences'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($topEntities === []): ?>
                        <tr><td colspan="4" class="muted">Совпадений пока нет.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="card half">
                <h2>Категории</h2>
                <table>
                    <thead>
                    <tr>
                        <th>Категория</th>
                        <th>Сущностей</th>
                        <th>Записей</th>
                        <th>Вхождений</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $categoryRow): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $categoryRow['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= (int) $categoryRow['entities_total'] ?></td>
                            <td><?= (int) $categoryRow['finding_rows'] ?></td>
                            <td><?= (int) $categoryRow['total_occurrences'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($categories === []): ?>
                        <tr><td colspan="4" class="muted">Категорий пока нет.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <section class="card full">
            <h2>Совпадения: <?= htmlspecialchars((string) ($reportTitles[$reportKey] ?? mb_strtoupper($reportKey)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="muted">
                Страница: <?= (int) ($pagination['current_page'] ?? 1) ?>
                · Всего страниц: <?= (int) ($pagination['total_pages'] ?? 1) ?>
            </p>
            <form method="post" action="/sites/<?= $siteId ?>/findings/revalidate" class="button-row" style="margin-bottom: 12px;">
                <input type="hidden" name="pattern_source" value="<?= htmlspecialchars($reportKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($buildFindingsUrl($siteId, $currentFullPage, $currentShortPage, $activeReport), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <button
                    type="submit"
                    class="warning"
                    data-revalidation-trigger
                    <?= $isRevalidationBusy ? 'disabled' : '' ?>
                ><?= $isRevalidationBusy ? 'Перепроверка запущена' : 'Перепроверить в фоне' ?></button>
            </form>
            <div
                class="revalidation-status"
                data-revalidation-status
                data-site-id="<?= $siteId ?>"
                data-report="<?= htmlspecialchars($reportKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            >
                <div><strong>Статус:</strong> <span data-revalidation-field="status"><?= htmlspecialchars((string) ($statusLabels[$revalidationStatus['status'] ?? 'never'] ?? ($revalidationStatus['status'] ?? 'never')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></div>
                <div class="revalidation-status-grid">
                    <div class="revalidation-status-item">
                        <span class="revalidation-status-label">Всего</span>
                        <span class="revalidation-status-value" data-revalidation-field="total_findings"><?= (int) ($revalidationStatus['total_findings'] ?? 0) ?></span>
                    </div>
                    <div class="revalidation-status-item">
                        <span class="revalidation-status-label">Проверено</span>
                        <span class="revalidation-status-value" data-revalidation-field="checked_findings"><?= (int) ($revalidationStatus['checked_findings'] ?? 0) ?></span>
                    </div>
                    <div class="revalidation-status-item">
                        <span class="revalidation-status-label">Осталось</span>
                        <span class="revalidation-status-value" data-revalidation-field="remaining_findings"><?= (int) ($revalidationStatus['remaining_findings'] ?? 0) ?></span>
                    </div>
                    <div class="revalidation-status-item">
                        <span class="revalidation-status-label">Удалено</span>
                        <span class="revalidation-status-value" data-revalidation-field="deleted_findings"><?= (int) ($revalidationStatus['deleted_findings'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="muted" style="margin-top: 8px;">
                    Обновлено: <span data-revalidation-field="updated_at"><?= htmlspecialchars((string) ($revalidationStatus['updated_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <div class="revalidation-status-error" data-revalidation-field="error_message"><?= htmlspecialchars((string) ($revalidationStatus['error_message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
            <table>
                <thead>
                <tr>
                    <th>Сущность</th>
                    <th>Страница</th>
                    <th>Фрагмент</th>
                    <th>Действия</th>
                </tr>
                </thead>
                <tbody id="findings-table-body" data-site-id="<?= $siteId ?>">
                <?php foreach ($findings as $finding): ?>
                    <?php
                    $fragmentText = (string) ($finding['context_excerpt'] ?: $finding['matched_text']);
                    $matchedText = (string) ($finding['matched_text'] ?? '');
                    $findingId = (int) ($finding['id'] ?? 0);
                    ?>
                    <tr data-finding-row data-finding-id="<?= $findingId ?>">
                        <td><?= htmlspecialchars((string) $finding['entity_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td>
                            <a href="<?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                                <?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </a>
                        </td>
                        <td class="mono"><?= $highlightFragment($fragmentText, $matchedText) ?></td>
                        <td>
                            <button
                                type="button"
                                class="finding-delete-button"
                                data-delete-finding
                                <?= $findingId > 0 ? '' : 'disabled' ?>
                            >Удалить</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($findings === []): ?>
                    <tr data-empty-row><td colspan="4" class="muted">Совпадений пока нет.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
                <div class="pagination">
                    <?php if (!empty($pagination['has_prev'])): ?>
                        <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl((int) ($pagination['prev_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">← Назад</a>
                    <?php endif; ?>
                    <?php for ($page = (int) ($pagination['start_page'] ?? 1); $page <= (int) ($pagination['end_page'] ?? 1); $page++): ?>
                        <?php if ($page === (int) ($pagination['current_page'] ?? 1)): ?>
                            <span class="page-link active"><?= $page ?></span>
                        <?php else: ?>
                            <a class="page-link" href="<?= htmlspecialchars($buildReportUrl($page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= $page ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if (!empty($pagination['has_next'])): ?>
                        <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl((int) ($pagination['next_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Вперед →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<script>
    (function () {
        var switchForm = document.querySelector('[data-report-switch-form]');
        if (!switchForm) {
            return;
        }

        var radios = switchForm.querySelectorAll('input[name="report"]');
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                switchForm.submit();
            });
        });
    })();

    (function () {
        var statusLabels = {
            never: 'Не запускалась',
            queued: 'В очереди',
            running: 'Выполняется',
            completed: 'Завершена',
            failed: 'Ошибка'
        };
        var statusBlocks = document.querySelectorAll('[data-revalidation-status]');
        statusBlocks.forEach(function (block) {
            var siteId = Number(block.getAttribute('data-site-id') || '0');
            var report = String(block.getAttribute('data-report') || 'full');
            if (!Number.isInteger(siteId) || siteId <= 0) {
                return;
            }

            var renderStatus = function (payload) {
                if (!payload || typeof payload !== 'object') {
                    return;
                }
                var status = String(payload.status || 'never');
                var fields = {
                    status: statusLabels[status] || status,
                    total_findings: String(Number(payload.total_findings || 0)),
                    checked_findings: String(Number(payload.checked_findings || 0)),
                    remaining_findings: String(Number(payload.remaining_findings || 0)),
                    deleted_findings: String(Number(payload.deleted_findings || 0)),
                    updated_at: String(payload.updated_at || ''),
                    error_message: String(payload.error_message || '')
                };
                Object.keys(fields).forEach(function (fieldName) {
                    var target = block.querySelector('[data-revalidation-field="' + fieldName + '"]');
                    if (target) {
                        target.textContent = fields[fieldName];
                    }
                });

                var trigger = block.parentElement ? block.parentElement.querySelector('[data-revalidation-trigger]') : null;
                if (trigger) {
                    var isBusy = status === 'queued' || status === 'running';
                    if (isBusy) {
                        trigger.setAttribute('disabled', 'disabled');
                        trigger.textContent = 'Перепроверка запущена';
                    } else {
                        trigger.removeAttribute('disabled');
                        trigger.textContent = 'Перепроверить в фоне';
                    }
                }

                return status;
            };

            var poll = function () {
                fetch('/api/sites/' + siteId + '/findings/revalidation-status?report=' + encodeURIComponent(report), {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                }).then(function (response) {
                    return response.json().catch(function () { return null; }).then(function (payload) {
                        return { response: response, payload: payload };
                    });
                }).then(function (result) {
                    if (!result.response.ok || !result.payload || result.payload.ok !== true || !result.payload.revalidation) {
                        return;
                    }
                    var status = renderStatus(result.payload.revalidation);
                    if (status === 'queued' || status === 'running') {
                        window.setTimeout(poll, 2500);
                    }
                }).catch(function () {
                    window.setTimeout(poll, 5000);
                });
            };

            var initialStatusNode = block.querySelector('[data-revalidation-field="status"]');
            var initialStatus = initialStatusNode ? String(initialStatusNode.textContent || '') : '';
            if (initialStatus === 'В очереди' || initialStatus === 'Выполняется') {
                window.setTimeout(poll, 1000);
            }
        });
    })();

    (function () {
        var findingsBody = document.getElementById('findings-table-body');
        if (!findingsBody) {
            return;
        }

        var siteId = Number(findingsBody.getAttribute('data-site-id') || '0');
        if (!Number.isInteger(siteId) || siteId <= 0) {
            return;
        }

        findingsBody.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var button = target.closest('[data-delete-finding]');
            if (!button) {
                return;
            }
            var row = button.closest('[data-finding-row]');
            if (!row) {
                return;
            }
            var findingId = Number(row.getAttribute('data-finding-id') || '0');
            if (!Number.isInteger(findingId) || findingId <= 0) {
                return;
            }
            if (!window.confirm('Удалить эту запись из таблицы совпадений?')) {
                return;
            }

            var originalLabel = button.textContent || 'Удалить';
            button.setAttribute('disabled', 'disabled');
            button.textContent = 'Удаление...';

            fetch('/api/sites/' + siteId + '/findings/' + findingId, {
                method: 'DELETE',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (payload) {
                    return { response: response, payload: payload };
                });
            }).then(function (result) {
                if (!result.response.ok || !result.payload || result.payload.ok !== true) {
                    throw new Error('delete_failed');
                }

                row.remove();
                var remaining = findingsBody.querySelectorAll('[data-finding-row]');
                if (remaining.length === 0) {
                    findingsBody.innerHTML = '<tr data-empty-row><td colspan="4" class="muted">Совпадений пока нет.</td></tr>';
                }
            }).catch(function () {
                button.removeAttribute('disabled');
                button.textContent = originalLabel;
                window.alert('Не удалось удалить запись. Обновите страницу и попробуйте снова.');
            });
        });
    })();
</script>
