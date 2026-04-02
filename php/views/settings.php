<?php

declare(strict_types=1);

/** @var array<string, string> $settings */
?>
<div class="grid">
    <section class="card full">
        <div class="page-head">
            <div>
                <h2 class="page-title">Settings</h2>
                <p class="page-subtitle">Конфигурация воркера и параметров crawler.</p>
            </div>
        </div>
    </section>

    <section class="card half">
        <h2>Планировщик и воркер</h2>
        <form method="post" action="/settings">
            <label>
                Сайтов за цикл воркера
                <input type="number" min="1" name="worker_batch_size" value="<?= (int) ($settings['worker_batch_size'] ?? 2) ?>">
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
                <input type="number" min="1" name="crawler_max_pages" value="<?= (int) ($settings['crawler_max_pages'] ?? 5000) ?>">
            </label>
            <label>
                Глубина обхода ссылок
                <input type="number" min="1" name="crawler_max_depth" value="<?= (int) ($settings['crawler_max_depth'] ?? 10) ?>">
            </label>
            <label>
                Таймаут страницы (мс)
                <input type="number" min="1000" step="1000" name="crawler_timeout_ms" value="<?= (int) ($settings['crawler_timeout_ms'] ?? 30000) ?>">
            </label>
            <label>
                Пауза между переходами страниц (мс)
                <input type="number" min="0" step="100" name="crawler_page_pause_ms" value="<?= (int) ($settings['crawler_page_pause_ms'] ?? 1000) ?>">
            </label>
            <p class="muted">Пауза применяется строго между запросами к следующей странице.</p>
            <p class="muted">На каждой странице crawler имитирует пользователя (скролл/клики), держит страницу 2–10 сек и принудительно уходит при 30+ сек.</p>
            <label>
                Максимальная длительность одного запуска (секунды)
                <input type="number" min="30" step="10" name="crawler_request_timeout_seconds" value="<?= (int) ($settings['crawler_request_timeout_seconds'] ?? 300) ?>">
            </label>
            <label>
                Попыток при ошибке crawler
                <input type="number" min="1" name="crawler_retry_attempts" value="<?= (int) ($settings['crawler_retry_attempts'] ?? 2) ?>">
            </label>
            <label>
                Пауза между повторами (мс)
                <input type="number" min="100" step="100" name="crawler_retry_delay_ms" value="<?= (int) ($settings['crawler_retry_delay_ms'] ?? 1500) ?>">
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
