<?php

declare(strict_types=1);

namespace App\Service;

final readonly class PatternDefinition
{
    private const PCRE_LIMIT_MATCH = 1000000;
    private const PCRE_LIMIT_DEPTH = 1000;

    public function __construct(
        public string $category,
        public string $entityName,
        public string $source,
        public string $pattern,
    ) {
    }

    public function toPregPattern(): string
    {
        $escaped = str_replace('~', '\~', $this->pattern);

        return "~(*LIMIT_MATCH=" . self::PCRE_LIMIT_MATCH . ")(*LIMIT_DEPTH=" . self::PCRE_LIMIT_DEPTH . "){$escaped}~iu";
    }
}
