<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PatternCatalog;
use PHPUnit\Framework\TestCase;

final class PatternCatalogTest extends TestCase
{
    public function testLoadsOnlySupportedCategoriesAndNonEmptyPatterns(): void
    {
        $catalog = new PatternCatalog(__DIR__ . '/fixtures/patterns.json');
        $patterns = $catalog->all();

        self::assertCount(4, $patterns);

        $categories = array_map(static fn ($pattern): string => $pattern->category, $patterns);
        sort($categories);

        self::assertSame(
            ['extremist', 'foreign_agent', 'foreign_agent', 'terrorist'],
            $categories
        );
    }

    public function testCanSkipShortPatterns(): void
    {
        $catalog = new PatternCatalog(__DIR__ . '/fixtures/patterns.json');
        $patterns = $catalog->all(false);

        self::assertCount(2, $patterns);

        $categories = array_map(static fn ($pattern): string => $pattern->category, $patterns);
        sort($categories);

        self::assertSame(
            ['extremist', 'foreign_agent'],
            $categories
        );
    }
}
