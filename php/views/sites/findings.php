<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var array<int, array<string, mixed>> $findings */
/** @var array<string, mixed> $summary */
/** @var array<int, array<string, mixed>> $top_entities */
/** @var array<string, mixed> $pagination */
/** @var callable $renderComponent */

$siteId = (int) $site['id'];
$currentPage = (int) ($pagination['current_page'] ?? 1);
$showSummaryBlocks = $currentPage === 1;
$buildFindingsUrl = static function (int $targetSiteId, int $targetPage): string {
    if ($targetPage <= 1) {
        return "/sites/{$targetSiteId}/findings";
    }

    return "/sites/{$targetSiteId}/findings?" . http_build_query(['findings_page' => $targetPage]);
};
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
            'returnTo' => "/sites/{$siteId}/findings",
            'showEdit' => false,
        ]) ?>
    </section>

    <?php if ($showSummaryBlocks): ?>
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
                <span class="label">Текущая страница</span>
                <span class="value"><?= $currentPage ?></span>
            </div>
            <p class="muted">Всего страниц: <?= (int) ($pagination['total_pages'] ?? 1) ?></p>
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
    <?php endif; ?>

    <section class="card full">
        <h2>Все совпадения</h2>
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
                    <a class="subtle-link" href="<?= htmlspecialchars($buildFindingsUrl($siteId, (int) ($pagination['prev_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">← Назад</a>
                <?php endif; ?>
                <?php for ($page = (int) ($pagination['start_page'] ?? 1); $page <= (int) ($pagination['end_page'] ?? 1); $page++): ?>
                    <?php if ($page === (int) ($pagination['current_page'] ?? 1)): ?>
                        <span class="page-link active"><?= $page ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= htmlspecialchars($buildFindingsUrl($siteId, $page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= $page ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if (!empty($pagination['has_next'])): ?>
                    <a class="subtle-link" href="<?= htmlspecialchars($buildFindingsUrl($siteId, (int) ($pagination['next_page'] ?? 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Вперед →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
