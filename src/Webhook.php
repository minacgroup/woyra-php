<?php

declare(strict_types=1);

namespace Woyra;

/**
 * Webhook signature verification.
 *
 * Woyra signs each delivery with the endpoint's secret:
 * `Woyra-Signature: v1=<hex HMAC-SHA256 of "<Woyra-Timestamp>.<raw body>">`.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Woyra-Signature';
    public const TIMESTAMP_HEADER = 'Woyra-Timestamp';
    /** Signatures older (or further in the future) than this many seconds are rejected. */
    public const DEFAULT_TOLERANCE = 300;

    /** Computes the Woyra-Signature value for a payload, exactly as Woyra does. */
    public static function sign(string $secret, int $timestamp, string $payload): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * Verifies a delivery and returns the decoded event as an array.
     *
     * $payload is the raw body exactly as received (file_get_contents('php://input')).
     * $headers may be getallheaders(), a PSR-7 $request->getHeaders(), or $_SERVER.
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, mixed>
     * @throws WebhookVerificationException
     */
    public static function verify(
        string $payload,
        array $headers,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        if ($secret === '') {
            throw new WebhookVerificationException('A webhook signing secret is required.', 'missing_secret');
        }
        $signatureHeader = self::header($headers, self::SIGNATURE_HEADER);
        $timestampHeader = self::header($headers, self::TIMESTAMP_HEADER);
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookVerificationException('Missing ' . self::SIGNATURE_HEADER . ' header.', 'missing_header');
        }
        if ($timestampHeader === null || $timestampHeader === '') {
            throw new WebhookVerificationException('Missing ' . self::TIMESTAMP_HEADER . ' header.', 'missing_header');
        }

        $trimmed = trim($timestampHeader);
        if (preg_match('/^\d{1,15}$/', $trimmed) !== 1) {
            throw new WebhookVerificationException(self::TIMESTAMP_HEADER . ' is not a unix timestamp.', 'invalid_timestamp');
        }
        $timestamp = (int) $trimmed;
        $now ??= time();
        // Checked in both directions: a far-future timestamp is as much a
        // forgery signal as a stale one.
        if (abs($now - $timestamp) > $tolerance) {
            throw new WebhookVerificationException('Webhook timestamp is outside the tolerance window.', 'signature_expired');
        }

        $expected = self::sign($secret, $timestamp, $payload);
        // One signature today; a list separated by commas or spaces is accepted
        // so a future secret rotation can send two side by side.
        $matched = false;
        foreach (preg_split('/[\s,]+/', $signatureHeader, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationException('Webhook signature does not match.', 'invalid_signature');
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationException('Webhook payload is not valid JSON.', 'invalid_payload');
        }
        if (!is_array($event)) {
            throw new WebhookVerificationException('Webhook payload is not a JSON object.', 'invalid_payload');
        }
        return $event;
    }

    /** @param array<string, string|string[]> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $wanted = strtolower($name);
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ($headers as $key => $value) {
            $key = (string) $key;
            if (strtolower($key) === $wanted || strtoupper($key) === $server) {
                return is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
            }
        }
        return null;
    }
}
