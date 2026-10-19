<?php

declare(strict_types=1);

namespace Woyra\Resources;

final class Templates extends Resource
{
    /**
     * Creates a template from ['name', 'subject', 'html'?, 'text'?].
     * {{name}} placeholders become its variables.
     *
     * @param array{name: string, subject: string, html?: string, text?: string} $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/v1/templates', null, $params);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/v1/templates/' . self::id($id));
    }

    /**
     * @param array{limit?: int, cursor?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/templates', $params);
    }

    /**
     * Changes any of name, subject, html and text.
     *
     * @param array{name?: string, subject?: string, html?: string, text?: string} $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', '/v1/templates/' . self::id($id), null, $params === [] ? new \stdClass() : $params);
    }

    public function remove(string $id): void
    {
        $this->client->request('DELETE', '/v1/templates/' . self::id($id));
    }
}
