<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var array<string, array<string, mixed>> $reports */
/** @var callable $renderComponent */

$siteId = (int) $site['id'];
/** @var array<string, mixed> $fullReport */
$fullReport = is_array($reports['full'] ?? null) ? $reports['full'] : [];
/** @var array<string, mixed> $shortReport */
$shortReport = is_array($reports['short'] ?? null) ? $reports['short'] : [];
$fullPagination = is_array($fullReport['pagination'] ?? null) ? $fullReport['pagination'] : ['current_page' => 1];
$shortPagination = is_array($shortReport['pagination'] ?? null) ? $shortReport['pagination'] : ['current_page' => 1];
$currentFullPage = (int) ($fullPagination['current_page'] ?? 1);
$currentShortPage = (int) ($shortPagination['current_page'] ?? 1);
$buildFindingsUrl = static function (int $targetSiteId, int $targetFullPage, int $targetShortPage): string {
    if ($targetFullPage <= 1 && $targetShortPage <= 1) {
        return "/sites/{$targetSiteId}/findings";
    }

    $query = [];
    if ($targetFullPage > 1) {
        $query['full_page'] = $targetFullPage;
    }
    if ($targetShortPage > 1) {
        $query['short_page'] = $targetShortPage;
    }

    return "/sites/{$targetSiteId}/findings?" . http_build_query($query);
};
$reportTitles = [
    'full' => 'FULL регулярки',
    'short' => 'SHORT регулярки',
];
$reportDescriptions = [
    'full' => 'Точное совпадение по полным выражениям.',
    'short' => 'Поиск по кратким вариантам регулярных выражений.',
];
?>
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
        <?= $renderComponent('site_actions', [
            'site' => $site,
            'returnTo' => $buildFindingsUrl($siteId, $currentFullPage, $currentShortPage),
            'showEdit' => false,
        ]) ?>
    </section>

    <?php foreach (['full', 'short'] as $reportKey): ?>
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
        $buildReportUrl = static function (string $sourceKey, int $targetPage) use (
            $buildFindingsUrl,
            $siteId,
            $currentFullPage,
            $currentShortPage
        ): string {
            if ($sourceKey === 'full') {
                return $buildFindingsUrl($siteId, $targetPage, $currentShortPage);
            }

            return $buildFindingsUrl($siteId, $currentFullPage, $targetPage);
        };
        ?>
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

        <section class="card full">
            <h2>Совпадения</h2>
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
                <?php foreach ($findings as $finding): ?>
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
                <?php if ($findings === []): ?>
                    <tr><td colspan="6" class="muted">Совпадений пока нет.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
                <div class="pagination">
                    <?php if (!empty($pagination['has_prev'])): ?>
                        <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl($reportKey, (int) ($pagination['prev_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">← Назад</a>
                    <?php endif; ?>
                    <?php for ($page = (int) ($pagination['start_page'] ?? 1); $page <= (int) ($pagination['end_page'] ?? 1); $page++): ?>
                        <?php if ($page === (int) ($pagination['current_page'] ?? 1)): ?>
                            <span class="page-link active"><?= $page ?></span>
                        <?php else: ?>
                            <a class="page-link" href="<?= htmlspecialchars($buildReportUrl($reportKey, $page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= $page ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if (!empty($pagination['has_next'])): ?>
                        <a class="subtle-link" href="<?= htmlspecialchars($buildReportUrl($reportKey, (int) ($pagination['next_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Вперед →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
