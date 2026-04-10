<?php

declare(strict_types=1);

/** @var array<string, string> $settings */
/** @var array{status:string,last_attempt_at:string,last_error:string} $regexSyncMeta */

$regexStatus = $regexSyncMeta['status'] ?? 'never';
$regexStatusLabels = [
    'never' => 'Не запускалось',
    'success' => 'Успешно',
    'error' => 'Ошибка',
];
$regexStatusLabel = $regexStatusLabels[$regexStatus] ?? $regexStatus;
$regexLastAttemptRaw = trim((string) ($regexSyncMeta['last_attempt_at'] ?? ''));
$regexLastAttemptLabel = 'Еще не было';

if ($regexLastAttemptRaw !== '') {
    try {
        $regexLastAttemptLabel = (new DateTimeImmutable($regexLastAttemptRaw))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d.m.Y H:i:s');
    } catch (Exception) {
        $regexLastAttemptLabel = $regexLastAttemptRaw;
    }
}
?>
<div class="grid">
    <section class="card full">
        <div class="page-head">
            <div>
                <h2 class="page-title">Settings</h2>
                <p class="page-subtitle">Конфигурация воркера и параметров crawler.</p>
                <p class="page-subtitle">Статус обновления регулярок: <strong><?= htmlspecialchars($regexStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
                <p class="page-subtitle">Последняя попытка: <?= htmlspecialchars($regexLastAttemptLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if (($regexSyncMeta['last_error'] ?? '') !== '' && $regexStatus === 'error'): ?>
                    <p class="page-subtitle">Ошибка: <?= htmlspecialchars((string) $regexSyncMeta['last_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div class="button-row">
                <form method="post" action="/settings/regex-refresh">
                    <button type="submit">Обновить регулярки</button>
                </form>
                <a class="subtle-link" href="/settings/regex-json" target="_blank" rel="noreferrer">Открыть текущий JSON</a>
            </div>
        </div>
    </section>

    <section class="card half">
        <h2>Планировщик и воркер</h2>
        <form method="post" action="/settings">
            <label>
                Сайтов за цикл воркера
                <input type="number" min="1" name="worker_batch_size" value="<?= (int) ($settings['worker_batch_size'] ?? 3) ?>">
            </label>
            <label>
                Параллельных сканов (1-3)
                <input type="number" min="1" max="3" name="worker_parallel_sites" value="<?= (int) ($settings['worker_parallel_sites'] ?? 1) ?>">
            </label>
            <label>
                Интервал автоскана (минуты)
                <input type="number" min="1" name="scan_interval_minutes" value="<?= (int) ($settings['scan_interval_minutes'] ?? 360) ?>">
            </label>
            <label>
                Минут до авто-сброса зависшего запуска
                <input type="number" min="1" name="worker_stale_run_minutes" value="<?= (int) ($settings['worker_stale_run_minutes'] ?? 5) ?>">
            </label>
            <button class="secondary" type="submit">Сохранить</button>
        </form>
    </section>

    <section class="card half">
        <h2>Crawler</h2>
        <form method="post" action="/settings">
            <label>
                Максимум страниц за обход
                <input type="number" min="1" name="crawler_max_pages" value="<?= (int) ($settings['crawler_max_pages'] ?? 10000) ?>">
            </label>
            <label>
                Глубина обхода ссылок
                <input type="number" min="1" name="crawler_max_depth" value="<?= (int) ($settings['crawler_max_depth'] ?? 15) ?>">
            </label>
            <label>
                Таймаут страницы (мс)
                <input type="number" min="1000" step="1000" name="crawler_timeout_ms" value="<?= (int) ($settings['crawler_timeout_ms'] ?? 45000) ?>">
            </label>
            <label>
                Пауза между переходами страниц (мс)
                <input type="number" min="0" step="100" name="crawler_page_pause_ms" value="<?= (int) ($settings['crawler_page_pause_ms'] ?? 1500) ?>">
            </label>
            <p class="muted">Пауза применяется строго между запросами к следующей странице.</p>
            <p class="muted">На каждой странице crawler имитирует пользователя (скролл/клики), держит страницу 2–10 сек и принудительно уходит при 30+ сек.</p>
            <label>
                Лимит времени одного обхода (секунды)
                <input type="number" min="30" step="30" name="crawler_max_duration_seconds" value="<?= (int) ($settings['crawler_max_duration_seconds'] ?? 3600) ?>">
            </label>
            <label>
                Таймаут ожидания ответа от crawler (секунды)
                <input type="number" min="30" step="10" name="crawler_request_timeout_seconds" value="<?= (int) ($settings['crawler_request_timeout_seconds'] ?? 600) ?>">
            </label>
            <label>
                Попыток при ошибке crawler
                <input type="number" min="1" name="crawler_retry_attempts" value="<?= (int) ($settings['crawler_retry_attempts'] ?? 3) ?>">
            </label>
            <label>
                Пауза между повторами (мс)
                <input type="number" min="100" step="100" name="crawler_retry_delay_ms" value="<?= (int) ($settings['crawler_retry_delay_ms'] ?? 2500) ?>">
            </label>
            <button type="submit">Сохранить</button>
        </form>
    </section>

    <section class="card full">
        <h2>Политика ошибок</h2>
        <p class="muted">
            При ошибке crawler сервис делает несколько попыток в рамках одного запуска.
            Если после всех попыток обход не удался, сайт автоматически переводится в паузу,
            чтобы воркер не генерировал десятки одинаковых ошибок в «Последние запуски».
        </p>
    </section>
</div>
