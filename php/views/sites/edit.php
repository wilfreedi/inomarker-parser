<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var callable $renderComponent */

$siteId = (int) $site['id'];
$siteName = (string) $site['name'];
$siteStatus = (string) ($site['status'] ?? 'idle');
?>
<div class="grid">
    <section class="card half">
        <div class="page-head">
            <div>
                <h2 class="page-title">Редактирование сайта</h2>
                <p class="page-subtitle">ID <?= $siteId ?> · <?= htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <a class="subtle-link" href="/sites">К списку сайтов</a>
        </div>

        <?= $renderComponent('site_form', [
            'action' => "/sites/{$siteId}",
            'submitLabel' => 'Сохранить изменения',
            'returnTo' => "/sites/{$siteId}/edit",
            'site' => $site,
        ]) ?>
    </section>

    <section class="card half">
        <h2>Управление сканированием</h2>
        <p class="muted">
            Текущий статус: <span id="live-status-badge"><?= $renderComponent('status_badge', ['status' => $siteStatus]) ?></span>
        </p>
        <div id="live-progress-box"><?= $renderComponent('site_progress', ['site' => $site]) ?></div>
        <p class="autorefresh-note">Статус и прогресс обновляются точечно через API, без перезагрузки страницы.</p>
        <?= $renderComponent('site_actions', [
            'site' => $site,
            'returnTo' => "/sites/{$siteId}/edit",
            'showEdit' => false,
        ]) ?>
    </section>

    <section class="card half danger-zone">
        <h2>Удаление сайта</h2>
        <p class="muted">
            Удаление необратимо. Будут удалены сайт, все запуски, страницы и найденные совпадения.
        </p>
        <form method="post" action="/sites/<?= $siteId ?>/delete" onsubmit="return confirm('Удалить сайт и все связанные данные?');">
            <label>
                Введите точное имя сайта или его URL/домен для подтверждения
                <input
                    type="text"
                    name="delete_confirmation"
                    placeholder="<?= htmlspecialchars((string) $site['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    required
                >
            </label>
            <button class="warning" type="submit">Удалить сайт</button>
        </form>
    </section>
</div>
<script>
(() => {
    const siteId = <?= $siteId ?>;
    const endpoint = `/api/sites/${siteId}/live`;
    const statusNode = document.getElementById('live-status-badge');
    const progressNode = document.getElementById('live-progress-box');
    if (!statusNode || !progressNode) {
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

    const tick = async () => {
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
            if (!payload || payload.ok !== true || !payload.site) {
                return;
            }
            statusNode.innerHTML = renderStatus(String(payload.site.status || 'idle'));
            progressNode.innerHTML = renderProgress(payload.site);
        } catch (_) {
            // Ignore polling errors.
        }
    };

    void tick();
    setInterval(() => {
        void tick();
    }, 2500);
})();
</script>
