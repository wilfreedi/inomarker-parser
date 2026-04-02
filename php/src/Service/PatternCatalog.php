<?php

declare(strict_types=1);

namespace App\Service;

final class PatternCatalog
{
    /** @var array<int, PatternDefinition> */
    private array $cache = [];
    private ?int $cachedMtime = null;

    public function __construct(private readonly string $jsonPath)
    {
    }

    /** @return array<int, PatternDefinition> */
    public function all(): array
    {
        $mtime = file_exists($this->jsonPath) ? filemtime($this->jsonPath) : null;
        if ($this->cache !== [] && $this->cachedMtime === $mtime) {
            return $this->cache;
        }

        if (!file_exists($this->jsonPath)) {
            throw new \RuntimeException("Regex source file is missing: {$this->jsonPath}");
        }

        $raw = file_get_contents($this->jsonPath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read regex source file: {$this->jsonPath}");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Regex source must be an object');
        }

        $patterns = [];
        foreach ($decoded as $category => $entities) {
            if (!in_array($category, ['foreign_agent', 'extremist', 'terrorist'], true)) {
                continue;
            }
            if (!is_array($entities)) {
                continue;
            }
            foreach ($entities as $entityName => $entityPatterns) {
                if (!is_array($entityPatterns)) {
                    continue;
                }
                $short = $entityPatterns['short'] ?? null;
                $full = $entityPatterns['full'] ?? null;

                if (is_string($short) && trim($short) !== '') {
                    $patterns[] = new PatternDefinition($category, (string) $entityName, 'short', $short);
                }
                if (is_string($full) && trim($full) !== '') {
                    $patterns[] = new PatternDefinition($category, (string) $entityName, 'full', $full);
                }
            }
        }

        $this->cachedMtime = $mtime;
        $this->cache = $patterns;

        return $patterns;
    }
}
