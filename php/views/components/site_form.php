<?php

declare(strict_types=1);

/** @var string $action */
/** @var string $submitLabel */
/** @var string $returnTo */
/** @var array<string, mixed>|null $site */

$siteData = $site ?? [];
$nameValue = htmlspecialchars((string) ($siteData['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$baseUrlValue = htmlspecialchars((string) ($siteData['base_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$returnToValue = htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= $returnToValue ?>">
    <label>
        Название
        <input type="text" name="name" value="<?= $nameValue ?>" placeholder="Например: Новости региона" required>
    </label>
    <label>
        Базовый URL
        <input type="url" name="base_url" value="<?= $baseUrlValue ?>" placeholder="https://example.org" required>
    </label>
    <button type="submit"><?= htmlspecialchars($submitLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
</form>
