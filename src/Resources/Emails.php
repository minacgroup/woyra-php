<?php

declare(strict_types=1);

namespace Woyra\Resources;

final class Emails extends Resource
{
    /**
     * Sends one email. An Idempotency-Key is generated unless you pass
     * ['idempotency_key' => '...'] in $options.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, timeout?: float} $options
     * @return array<string, mixed>
     */
    public function send(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/v1/emails', null, self::serialise($params), $options);
    }

    /**
     * Sends up to 100 emails, all or nothing, under one idempotency key.
     *
     * @param list<array<string, mixed>> $emails
     * @param array{idempotency_key?: string, timeout?: float} $options
     * @return array<string, mixed>
     */
    public function batch(array $emails, array $options = []): array
    {
        $body = array_map([self::class, 'serialise'], array_values($emails));
        return $this->client->request('POST', '/v1/emails/batch', null, $body, $options);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/v1/emails/' . self::id($id));
    }

    /**
     * Lists emails, newest first. Pass next_cursor back as cursor.
     *
     * @param array{limit?: int, cursor?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/emails', $params);
    }

    /**
     * Cancels a scheduled email before it leaves.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/v1/emails/' . self::id($id) . '/cancel');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function serialise(array $params): array
    {
        if (($params['scheduled_at'] ?? null) instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($params['scheduled_at'])->setTimezone(new \DateTimeZone('UTC'));
            $params['scheduled_at'] = $utc->format('Y-m-d\TH:i:s\Z');
        }
        // An empty PHP array would encode as [], but these are JSON objects.
        foreach (['headers', 'metadata', 'variables'] as $key) {
            if (isset($params[$key]) && $params[$key] === []) {
                $params[$key] = new \stdClass();
            }
        }
        return $params;
    }
}
