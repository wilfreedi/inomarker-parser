<?php

declare(strict_types=1);

namespace App\Service;

final class CrawlerClient
{
    /** @var null|callable(string, string): array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} */
    private $transport;

    /**
     * @param null|callable(string, string): array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} $transport
     */
    public function __construct(
        private readonly string $endpoint,
        ?callable $transport = null,
        private readonly string $progressEndpoint = '',
        private readonly string $progressToken = '',
    ) {
        $this->transport = $transport;
    }

    /**
     * @param array<string, int> $options
     * @return array<int, array<string, mixed>>
     */
    public function crawl(string $siteUrl, array $options): array
    {
        $requestTimeoutSeconds = max(30, (int) ($options['request_timeout_seconds'] ?? 300));
        $pageTimeoutMs = max(5_000, (int) ($options['timeout_ms'] ?? 30_000));
        $maxDurationMs = max(
            $pageTimeoutMs,
            ($requestTimeoutSeconds * 1000) - 5_000
        );

        $payloadData = [
            'siteUrl' => $siteUrl,
            'maxPages' => $options['max_pages'] ?? 100,
            'maxDepth' => $options['max_depth'] ?? 2,
            'timeoutMs' => $pageTimeoutMs,
            'pagePauseMs' => $options['page_pause_ms'] ?? 1000,
            'maxDurationMs' => $maxDurationMs,
        ];
        if (
            $this->progressEndpoint !== ''
            && isset($options['site_id'], $options['run_id'])
            && (int) $options['site_id'] > 0
            && (int) $options['run_id'] > 0
        ) {
            $payloadData['progressCallback'] = [
                'url' => $this->progressEndpoint,
                'token' => $this->progressToken,
                'siteId' => (int) $options['site_id'],
                'runId' => (int) $options['run_id'],
            ];
        }

        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);
        $attempts = max(1, (int) ($options['retry_attempts'] ?? 2));
        $retryDelayMs = max(100, (int) ($options['retry_delay_ms'] ?? 1500));
        $lastErrorMessage = 'Crawler request failed';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->performRequest($payload, $requestTimeoutSeconds);
            if ($result['ok']) {
                /** @var mixed $decoded */
                $decoded = json_decode((string) $result['body'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
                    throw new \RuntimeException('Crawler response is malformed');
                }

                return $decoded['pages'];
            }

            $lastErrorMessage = (string) $result['error'];
            if ($attempt >= $attempts || !$this->isRetryable((int) $result['status'], (bool) $result['curl_failed'])) {
                break;
            }
            usleep($retryDelayMs * 1000);
        }

        throw new \RuntimeException("Crawler failed after {$attempts} attempts: {$lastErrorMessage}");
    }

    /** @return array{ok:bool,status:int,body:string|null,error:string|null,curl_failed:bool} */
    private function performRequest(string $payload, int $requestTimeoutSeconds): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($this->endpoint, $payload);
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(30, $requestTimeoutSeconds),
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
                'error' => 'Crawler connection failed: ' . $curlError,
                'curl_failed' => true,
            ];
        }

        if ($statusCode >= 400) {
            return [
                'ok' => false,
                'status' => $statusCode,
                'body' => $body,
                'error' => "Crawler error [{$statusCode}]: {$body}",
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

    private function isRetryable(int $statusCode, bool $curlFailed): bool
    {
        if ($curlFailed) {
            return true;
        }

        return $statusCode === 408 || $statusCode === 429 || $statusCode >= 500;
    }
}
