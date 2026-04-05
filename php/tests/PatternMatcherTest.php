<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PatternDefinition;
use App\Service\PatternMatcher;
use PHPUnit\Framework\TestCase;

final class PatternMatcherTest extends TestCase
{
    public function testAggregatesOccurrencesSeparatelyByPatternSource(): void
    {
        $matcher = new PatternMatcher([
            new PatternDefinition('foreign_agent', 'Entity A', 'full', '(entity\\s+a)'),
            new PatternDefinition('foreign_agent', 'Entity A', 'short', '(ent\\s*a)'),
            new PatternDefinition('terrorist', 'Entity B', 'full', '(entity\\s+b)'),
        ]);

        $text = 'Entity A and ent a were mentioned. Also entity b appears once. Ent a appeared again.';
        $matches = $matcher->match($text);

        self::assertCount(3, $matches);

        $byEntityAndSource = [];
        foreach ($matches as $match) {
            $key = $match['entity_name'] . '|' . $match['pattern_source'];
            $byEntityAndSource[$key] = $match;
        }

        self::assertSame(1, $byEntityAndSource['Entity A|full']['occurrences']);
        self::assertSame(2, $byEntityAndSource['Entity A|short']['occurrences']);
        self::assertSame('foreign_agent', $byEntityAndSource['Entity A|full']['category']);
        self::assertSame('foreign_agent', $byEntityAndSource['Entity A|short']['category']);
        self::assertSame(1, $byEntityAndSource['Entity B|full']['occurrences']);
        self::assertSame('terrorist', $byEntityAndSource['Entity B|full']['category']);
    }
}
