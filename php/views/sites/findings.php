<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var array<string, array<string, mixed>> $reports */
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
            <table>
                <thead>
                <tr>
                    <th>Сущность</th>
                    <th>Страница</th>
                    <th>Фрагмент</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($findings as $finding): ?>
                    <?php
                    $fragmentText = (string) ($finding['context_excerpt'] ?: $finding['matched_text']);
                    $matchedText = (string) ($finding['matched_text'] ?? '');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $finding['entity_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td>
                            <a href="<?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                                <?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </a>
                        </td>
                        <td class="mono"><?= $highlightFragment($fragmentText, $matchedText) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($findings === []): ?>
                    <tr><td colspan="3" class="muted">Совпадений пока нет.</td></tr>
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
</script>
