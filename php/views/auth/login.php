<?php

declare(strict_types=1);

/** @var string|null $return_to */
$returnTo = trim((string) ($return_to ?? '/'));
if ($returnTo === '') {
    $returnTo = '/';
}
?>
<div class="grid">
    <section class="card full" style="max-width: 460px; margin: 30px auto;">
        <h2>Вход</h2>
        <p class="muted">Введите секретный пароль для доступа к админке.</p>
        <form method="post" action="/login">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <label>
                Пароль
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <div class="button-row">
                <button type="submit">Войти</button>
            </div>
        </form>
    </section>
</div>
