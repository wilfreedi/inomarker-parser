<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */

$status = (string) ($site['status'] ?? 'idle');
$pages = (int) ($site['progress_pages'] ?? 0);
$currentUrl = trim((string) ($site['progress_current_url'] ?? ''));
$updatedAt = (string) ($site['progress_updated_at'] ?? '');
?>
<?php if ($status === 'running'): ?>
    <div class="progress-live">
        <span class="dot-live" aria-hidden="true"></span>
        <span class="progress-text">Робот обходит страницы</span>
        <span class="progress-text">Обработано: <strong><?= $pages ?></strong></span>
        <?php if ($currentUrl !== ''): ?>
            <span class="progress-text">
                Текущая:
                <a class="subtle-link" href="<?= htmlspecialchars($currentUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                    <?= htmlspecialchars($currentUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
            </span>
        <?php endif; ?>
        <?php if ($updatedAt !== ''): ?>
            <span class="progress-text muted">Обновлено: <?= htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
<?php else: ?>
    <span class="muted">-</span>
<?php endif; ?>
