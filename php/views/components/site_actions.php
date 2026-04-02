<?php

declare(strict_types=1);

/** @var array<string, mixed> $site */
/** @var string $returnTo */
/** @var bool $showEdit */

$status = (string) $site['status'];
$siteId = (int) $site['id'];
$showEditAction = isset($showEdit) ? (bool) $showEdit : true;
$returnToSafe = htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="button-row">
    <?php if ($status === 'running'): ?>
        <form method="post" action="/sites/<?= $siteId ?>/pause">
            <input type="hidden" name="return_to" value="<?= $returnToSafe ?>">
            <button class="warning" type="submit">Пауза</button>
        </form>
        <form method="post" action="/sites/<?= $siteId ?>/cancel">
            <input type="hidden" name="return_to" value="<?= $returnToSafe ?>">
            <button class="ghost" type="submit">Отмена</button>
        </form>
    <?php elseif ($status === 'paused' || $status === 'cancel_requested'): ?>
        <form method="post" action="/sites/<?= $siteId ?>/resume">
            <input type="hidden" name="return_to" value="<?= $returnToSafe ?>">
            <button class="secondary" type="submit">Возобновить</button>
        </form>
        <form method="post" action="/sites/<?= $siteId ?>/scan">
            <input type="hidden" name="return_to" value="<?= $returnToSafe ?>">
            <button type="submit">Сканировать</button>
        </form>
    <?php else: ?>
        <form method="post" action="/sites/<?= $siteId ?>/scan">
            <input type="hidden" name="return_to" value="<?= $returnToSafe ?>">
            <button type="submit">Сканировать</button>
        </form>
        <?php if (!empty($site['last_crawled_at'])): ?>
            <form method="post" action="/sites/<?= $siteId ?>/recrawl" onsubmit="return confirm('Очистить все данные сайта и запустить переобход?');">
                <input type="hidden" name="return_to" value="<?= $returnToSafe ?>">
                <button class="secondary" type="submit">Переобход с нуля</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($showEditAction): ?>
        <a class="subtle-link" href="/sites/<?= $siteId ?>/edit">Редактировать</a>
    <?php endif; ?>
</div>
