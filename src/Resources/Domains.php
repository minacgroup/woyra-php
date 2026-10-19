<?php

declare(strict_types=1);

namespace Woyra\Resources;

final class Domains extends Resource
{
    /**
     * Registers a sending domain and returns the DNS records to publish.
     *
     * @return array<string, mixed>
     */
    public function create(string $domain): array
    {
        return $this->client->request('POST', '/v1/domains', null, ['domain' => $domain]);
    }

    /**
     * @param array{limit?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/domains', $params);
    }

    /**
     * Re-checks the domain's DNS. Safe to call repeatedly.
     *
     * @return array<string, mixed>
     */
    public function verify(string $id): array
    {
        return $this->client->request('POST', '/v1/domains/' . self::id($id) . '/verify');
    }

    public function remove(string $id): void
    {
        $this->client->request('DELETE', '/v1/domains/' . self::id($id));
    }
}
