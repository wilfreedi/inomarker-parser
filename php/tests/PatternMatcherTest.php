<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PatternDefinition;
use App\Service\PatternMatcher;
use PHPUnit\Framework\TestCase;

final class PatternMatcherTest extends TestCase
{
    public function testAggregatesOccurrencesByEntityAndCategory(): void
    {
        $matcher = new PatternMatcher([
            new PatternDefinition('foreign_agent', 'Entity A', 'full', '(entity\\s+a)'),
            new PatternDefinition('foreign_agent', 'Entity A', 'short', '(ent\\s*a)'),
            new PatternDefinition('terrorist', 'Entity B', 'full', '(entity\\s+b)'),
        ]);

        $text = 'Entity A and entity a were mentioned. Also entity b appears once.';
        $matches = $matcher->match($text);

        self::assertCount(2, $matches);

        $byEntity = [];
        foreach ($matches as $match) {
            $byEntity[$match['entity_name']] = $match;
        }

        self::assertSame(2, $byEntity['Entity A']['occurrences']);
        self::assertSame('foreign_agent', $byEntity['Entity A']['category']);
        self::assertSame(1, $byEntity['Entity B']['occurrences']);
        self::assertSame('terrorist', $byEntity['Entity B']['category']);
    }
}
