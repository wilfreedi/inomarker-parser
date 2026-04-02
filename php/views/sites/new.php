<?php

declare(strict_types=1);

/** @var callable $renderComponent */
?>
<div class="grid">
    <section class="card half">
        <div class="page-head">
            <div>
                <h2 class="page-title">Добавить сайт</h2>
                <p class="page-subtitle">Новый сайт попадет в каталог и сможет сканироваться вручную или по расписанию.</p>
            </div>
            <a class="subtle-link" href="/sites">К списку сайтов</a>
        </div>
        <?= $renderComponent('site_form', [
            'action' => '/sites',
            'submitLabel' => 'Добавить сайт',
            'returnTo' => '/sites/new',
            'site' => null,
        ]) ?>
    </section>

    <section class="card half">
        <h2>Правила</h2>
        <p class="muted">
            URL нормализуется автоматически. Дубли по URL не допускаются.
            Если crawler регулярно падает, сайт будет переведен на паузу автоматически.
        </p>
    </section>
</div>
