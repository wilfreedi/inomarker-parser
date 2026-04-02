<?php

declare(strict_types=1);

namespace App\Service;

final readonly class PatternDefinition
{
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

        return "~{$escaped}~iu";
    }
}
