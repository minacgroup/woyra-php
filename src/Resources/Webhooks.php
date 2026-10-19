<?php

declare(strict_types=1);

namespace Woyra\Resources;

use Woyra\Webhook;

final class Webhooks extends Resource
{
    /**
     * Registers an endpoint. The response carries its signing secret, once.
     *
     * @param list<string>|null $events Event types to send; null or empty means every one.
     * @return array<string, mixed>
     */
    public function create(string $url, ?array $events = null): array
    {
        $body = ['url' => $url];
        if ($events !== null) {
            $body['events'] = array_values($events);
        }
        return $this->client->request('POST', '/v1/webhook_endpoints', null, $body);
    }

    /**
     * @param array{limit?: int, cursor?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/webhook_endpoints', $params);
    }

    public function remove(string $id): void
    {
        $this->client->request('DELETE', '/v1/webhook_endpoints/' . self::id($id));
    }

    /**
     * Verifies a delivery's signature and returns the decoded event. See Webhook::verify().
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, mixed>
     */
    public function verify(string $payload, array $headers, string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        return Webhook::verify($payload, $headers, $secret, $tolerance, $now);
    }
}
