<?php

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $runs */
/** @var array<int, array<string, mixed>> $findings */
/** @var array<string, int> $stats */
/** @var callable $renderComponent */

$runsCount = count($runs);
$failedRuns = (int) ($stats['runs_failed'] ?? 0);
$runsHealth = $runsCount > 0 ? (int) round((($runsCount - $failedRuns) / $runsCount) * 100) : 100;
?>
<div class="grid">
    <section class="card full">
        <div class="page-head">
            <div>
                <h2 class="page-title">Dashboard</h2>
                <p class="page-subtitle">Оперативный обзор состояния сервиса и последних запусков.</p>
            </div>
            <div class="page-actions">
                <a class="subtle-link" href="/sites">Сайты</a>
                <a class="subtle-link" href="/sites/new">Добавить сайт</a>
                <a class="subtle-link" href="/settings">Настройки</a>
            </div>
        </div>
    </section>

    <section class="card third">
        <div class="metric">
            <span class="label">Всего сайтов</span>
            <span class="value"><?= (int) ($stats['sites_total'] ?? 0) ?></span>
        </div>
    </section>
    <section class="card third">
        <div class="metric">
            <span class="label">Сканируются сейчас</span>
            <span class="value"><?= (int) ($stats['sites_running'] ?? 0) ?></span>
        </div>
    </section>
    <section class="card third">
        <div class="metric">
            <span class="label">На паузе</span>
            <span class="value"><?= (int) ($stats['sites_paused'] ?? 0) ?></span>
        </div>
    </section>

    <section class="card half">
        <h2>Надежность</h2>
        <div class="metric">
            <span class="label">Успешность последних запусков</span>
            <span class="value"><?= $runsHealth ?>%</span>
        </div>
        <p class="muted">
            Всего запусков в таблице: <strong><?= $runsCount ?></strong><br>
            Ошибочных запусков: <strong><?= $failedRuns ?></strong>
        </p>
    </section>

    <section class="card half">
        <h2>Найденные совпадения</h2>
        <p class="muted">
            В таблице ниже показаны последние <strong><?= count($findings) ?></strong> записей.<br>
            Всего вхождений по ним: <strong><?= (int) ($stats['findings_total'] ?? 0) ?></strong>.
        </p>
    </section>

    <section class="card full">
        <h2>Последние запуски</h2>
        <table>
            <thead>
            <tr>
                <th>Run ID</th>
                <th>Сайт</th>
                <th>Статус</th>
                <th>Страниц</th>
                <th>С совпадениями</th>
                <th>Старт</th>
                <th>Финиш</th>
                <th>Ошибка</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= (int) $run['id'] ?></td>
                    <td>
                        <a class="subtle-link" href="/sites/<?= (int) $run['site_id'] ?>">
                            <?= htmlspecialchars((string) $run['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </td>
                    <td><?= $renderComponent('status_badge', ['status' => (string) $run['status']]) ?></td>
                    <td><?= (int) $run['pages_total'] ?></td>
                    <td><?= (int) $run['pages_with_matches'] ?></td>
                    <td><?= htmlspecialchars((string) $run['started_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($run['finished_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="mono"><?= htmlspecialchars((string) ($run['error_message'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($runs === []): ?>
                <tr>
                    <td colspan="8" class="muted">Запусков пока нет.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Найденные вхождения</h2>
        <table>
            <thead>
            <tr>
                <th>Время</th>
                <th>Сайт</th>
                <th>Страница</th>
                <th>Категория</th>
                <th>Кого нашли</th>
                <th>Кол-во</th>
                <th>Фрагмент</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($findings as $finding): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $finding['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $finding['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                        <a href="<?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                            <?= htmlspecialchars((string) $finding['page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars((string) $finding['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $finding['entity_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= (int) $finding['occurrences'] ?></td>
                    <td class="mono"><?= htmlspecialchars((string) ($finding['context_excerpt'] ?: $finding['matched_text']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($findings === []): ?>
                <tr>
                    <td colspan="7" class="muted">Совпадений пока нет.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
