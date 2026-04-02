<?php

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $sites */
/** @var callable $renderComponent */
?>
<div class="grid">
    <section class="card full">
        <div class="page-head">
            <div>
                <h2 class="page-title">Сайты</h2>
                <p class="page-subtitle">Отдельный каталог сайтов с управлением сканированием, статусами и редактированием.</p>
            </div>
            <div class="page-actions">
                <a class="subtle-link" href="/sites/new">+ Добавить сайт</a>
            </div>
        </div>

        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>URL</th>
                <th>Статус</th>
                <th>Прогресс</th>
                <th>Последний обход</th>
                <th>Ошибка</th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $site): ?>
                <tr data-live-site-row="<?= (int) $site['id'] ?>">
                    <td><?= (int) $site['id'] ?></td>
                    <td>
                        <a class="subtle-link" href="/sites/<?= (int) $site['id'] ?>">
                            <?= htmlspecialchars((string) $site['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                            <?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </td>
                    <td class="js-live-status"><?= $renderComponent('status_badge', ['status' => (string) $site['status']]) ?></td>
                    <td class="js-live-progress"><?= $renderComponent('site_progress', ['site' => $site]) ?></td>
                    <td><?= htmlspecialchars((string) ($site['last_crawled_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="mono"><?= htmlspecialchars((string) ($site['last_error'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                        <?= $renderComponent('site_actions', ['site' => $site, 'returnTo' => '/sites', 'showEdit' => true]) ?>
                        <a class="subtle-link" href="/sites/<?= (int) $site['id'] ?>">Отчет</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($sites === []): ?>
                <tr>
                    <td colspan="8" class="muted">Сайтов пока нет. Добавьте первый сайт.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p class="autorefresh-note">Статусы и прогресс обновляются точечно через API, без перезагрузки страницы.</p>
    </section>
</div>
<script>
(() => {
    const rows = Array.from(document.querySelectorAll('[data-live-site-row]'));
    if (rows.length === 0) {
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

    const tickRow = async (row) => {
        const siteId = Number(row.getAttribute('data-live-site-row') || 0);
        if (siteId <= 0) {
            return;
        }
        try {
            const response = await fetch(`/api/sites/${siteId}/live`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (!payload || payload.ok !== true || !payload.site) {
                return;
            }
            const statusCell = row.querySelector('.js-live-status');
            const progressCell = row.querySelector('.js-live-progress');
            if (statusCell) {
                statusCell.innerHTML = renderStatus(String(payload.site.status || 'idle'));
            }
            if (progressCell) {
                progressCell.innerHTML = renderProgress(payload.site);
            }
        } catch (_) {
            // Ignore polling errors.
        }
    };

    const tickAll = () => Promise.all(rows.map((row) => tickRow(row)));
    void tickAll();
    setInterval(() => {
        void tickAll();
    }, 3000);
})();
</script>
