<?php

declare(strict_types=1);

namespace App\Service;

final class DetachedConsoleLauncher
{
    public function __construct(
        private readonly string $consolePath,
    ) {
    }

    /**
     * @param array<int, string|int> $arguments
     */
    public function launch(array $arguments): void
    {
        $escapedArgs = array_map(
            static fn (string|int $argument): string => escapeshellarg((string) $argument),
            $arguments
        );
        $command = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->consolePath),
            implode(' ', $escapedArgs)
        );

        $process = proc_open(
            ['/bin/sh', '-c', $command],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
            dirname($this->consolePath)
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to launch detached console command');
        }

        proc_close($process);
    }
}
