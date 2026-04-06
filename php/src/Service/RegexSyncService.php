<?php

declare(strict_types=1);

namespace App\Service;

final class RegexSyncService
{
    /** @var null|callable(string): array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} */
    private $transport;

    /**
     * @param null|callable(string): array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} $transport
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $targetPath,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function refresh(): void
    {
        if ($this->endpoint === '') {
            throw new \RuntimeException('Regex sync endpoint is not configured');
        }
        if ($this->apiKey === '') {
            throw new \RuntimeException('Regex sync api key is not configured');
        }

        $url = $this->endpoint . (str_contains($this->endpoint, '?') ? '&' : '?') . http_build_query([
            'api_key' => $this->apiKey,
        ]);

        $result = $this->performRequest($url);
        if (!$result['ok']) {
            throw new \RuntimeException((string) $result['error']);
        }

        $decoded = json_decode((string) $result['body'], true, 512, JSON_THROW_ON_ERROR);
        $normalized = $this->validatePayload($decoded);
        $json = json_encode(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $directory = dirname($this->targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create regex storage directory: {$directory}");
        }

        $written = file_put_contents($this->targetPath, $json . PHP_EOL, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Cannot write regex file: {$this->targetPath}");
        }
    }

    /** @return array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} */
    private function performRequest(string $url): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_NOSIGNAL => true,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'error' => 'Regex sync connection failed: ' . $curlError,
                'curl_failed' => true,
            ];
        }

        if ($statusCode >= 400) {
            return [
                'ok' => false,
                'status' => $statusCode,
                'body' => $body,
                'error' => "Regex sync error [{$statusCode}]: {$body}",
                'curl_failed' => false,
            ];
        }

        return [
            'ok' => true,
            'status' => $statusCode,
            'body' => $body,
            'error' => null,
            'curl_failed' => false,
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, array<string, array{short:null|string,full:null|string}>>
     */
    private function validatePayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException('Regex sync payload must be a JSON object');
        }

        $normalized = [];
        foreach ($payload as $category => $entities) {
            if (!is_string($category) || !is_array($entities)) {
                continue;
            }

            $normalized[$category] = [];
            foreach ($entities as $entityName => $patterns) {
                if (!is_string($entityName) || !is_array($patterns)) {
                    continue;
                }

                $short = $patterns['short'] ?? null;
                $full = $patterns['full'] ?? null;
                if ($short !== null && !is_string($short)) {
                    throw new \RuntimeException("Regex sync payload has invalid short pattern for entity: {$entityName}");
                }
                if ($full !== null && !is_string($full)) {
                    throw new \RuntimeException("Regex sync payload has invalid full pattern for entity: {$entityName}");
                }

                $normalized[$category][$entityName] = [
                    'short' => $short,
                    'full' => $full,
                ];
            }
        }

        if ($normalized === []) {
            throw new \RuntimeException('Regex sync payload is empty');
        }

        return $normalized;
    }
}
