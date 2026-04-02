<?php

declare(strict_types=1);

/** @var string $status */
$labels = [
    'idle' => 'Готов',
    'running' => 'Сканируется',
    'failed' => 'Ошибка',
    'paused' => 'Пауза',
    'cancel_requested' => 'Остановка',
];
$safeStatus = htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$label = htmlspecialchars($labels[$status] ?? $status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<span class="status-pill status-<?= $safeStatus ?>"><?= $label ?></span>
