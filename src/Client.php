<?php

declare(strict_types=1);

namespace Woyra;

use Woyra\Resources\Domains;
use Woyra\Resources\Emails;
use Woyra\Resources\Templates;
use Woyra\Resources\Webhooks;

/**
 * A Woyra API client.
 *
 * The API key defaults to the WOYRA_API_KEY environment variable and the base
 * URL to WOYRA_BASE_URL, then https://api.woyra.io. Network errors, 429 and
 * 5xx are retried max_retries times with exponential backoff and jitter,
 * honouring Retry-After. Other 4xx are never retried.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.woyra.io';

    public readonly Emails $emails;
    public readonly Templates $templates;
    public readonly Domains $domains;
    public readonly Webhooks $webhooks;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $maxRetries;
    private readonly float $timeout;
    private readonly ?string $apiVersion;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly float $retryBaseDelay;
    private readonly float $retryMaxDelay;

    /**
     * @param array{
     *     base_url?: string,
     *     max_retries?: int,
     *     timeout?: float,
     *     api_version?: string,
     *     headers?: array<string, string>,
     *     retry_base_delay?: float,
     *     retry_max_delay?: float,
     * } $options timeout and delays are in seconds.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? (getenv('WOYRA_API_KEY') ?: null);
        if ($key === null || $key === '') {
            throw new WoyraException('Missing API key. Pass it to new Woyra\\Client(...) or set WOYRA_API_KEY.', 0, 'authentication_required');
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($options['base_url'] ?? (getenv('WOYRA_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');
        $this->maxRetries = max(0, $options['max_retries'] ?? 3);
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->apiVersion = $options['api_version'] ?? null;
        $this->headers = $options['headers'] ?? [];
        $this->retryBaseDelay = (float) ($options['retry_base_delay'] ?? 0.5);
        $this->retryMaxDelay = (float) ($options['retry_max_delay'] ?? 30.0);

        $this->emails = new Emails($this);
        $this->templates = new Templates($this);
        $this->domains = new Domains($this);
        $this->webhooks = new Webhooks($this);
    }

    /**
     * Sends one API request with retries, idempotency and typed errors.
     *
     * @param array<string, scalar|null>|null $query
     * @param array{idempotency_key?: string, timeout?: float} $options
     * @return array<mixed>
     * @throws WoyraException
     */
    public function request(string $method, string $path, ?array $query = null, mixed $body = null, array $options = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $query = array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, array_filter($query, static fn ($v) => $v !== null));
            if ($query !== []) {
                $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'woyra-php/' . self::VERSION,
        ] + $this->headers;
        if ($this->apiVersion !== null) {
            $headers['Woyra-Version'] = $this->apiVersion;
        }
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }
        // Generated once, before the first attempt, so every retry carries the
        // same key and a retried send can never become a second send.
        if ($method === 'POST') {
            $headers['Idempotency-Key'] = $options['idempotency_key'] ?? self::uuid4();
        }
        // Disable curl's "Expect: 100-continue" round trip on larger bodies.
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $timeout = (float) ($options['timeout'] ?? $this->timeout);

        for ($attempt = 0; ; $attempt++) {
            $result = $this->send($method, $url, $lines, $payload, $timeout);
            if ($result['error'] !== null) {
                if ($attempt < $this->maxRetries) {
                    self::sleep($this->backoff($attempt));
                    continue;
                }
                throw new ConnectionException('Could not reach Woyra at ' . $this->baseUrl . ': ' . $result['error']);
            }

            $status = $result['status'];
            if ($status >= 200 && $status < 300) {
                if ($result['body'] === '') {
                    return [];
                }
                $decoded = json_decode($result['body'], true);
                return is_array($decoded) ? $decoded : [];
            }
            if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                $retryAfter = self::parseRetryAfter($result['headers']['retry-after'] ?? null);
                self::sleep(min($this->retryMaxDelay, $retryAfter ?? $this->backoff($attempt)));
                continue;
            }
            throw self::errorFromResponse($status, $result['body'], $result['headers']);
        }
    }

    /**
     * Parses Retry-After (seconds or an HTTP date) into seconds.
     */
    public static function parseRetryAfter(?string $value, ?int $now = null): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $when = strtotime($value);
        if ($when === false) {
            return null;
        }
        return (float) max(0, $when - ($now ?? time()));
    }

    private function backoff(int $attempt): float
    {
        $exp = min($this->retryMaxDelay, $this->retryBaseDelay * (2 ** $attempt));
        // Equal jitter: half fixed, half random, so concurrent clients spread out.
        return $exp / 2 + (mt_rand() / mt_getrandmax()) * ($exp / 2);
    }

    private static function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>, error: ?string}
     */
    private function send(string $method, string $url, array $headers, ?string $payload, float $timeout): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($timeout, 10.0) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $error = null;
        if ($body === false) {
            $error = curl_error($ch) ?: 'unknown network error';
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $responseHeaders, 'error' => $error];
    }

    /** @param array<string, string> $headers */
    private static function errorFromResponse(int $status, string $body, array $headers): WoyraException
    {
        $headerRequestId = $headers['woyra-request-id'] ?? null;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $e = $decoded['error'];
            $s = static fn (string $k): ?string => isset($e[$k]) && is_string($e[$k]) ? $e[$k] : null;
            return new WoyraException(
                $s('message') ?? 'HTTP ' . $status,
                $status,
                $s('code') ?? 'unknown_error',
                $s('param'),
                $s('request_id') ?? $headerRequestId,
                $s('doc_url'),
            );
        }
        return new WoyraException('HTTP ' . $status, $status, 'unknown_error', null, $headerRequestId);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
