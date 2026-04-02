<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    throw new RuntimeException('Composer autoload is missing. Run "composer install" in /php.');
}

require $autoloadPath;
