<?php

declare(strict_types=1);

namespace Woyra;

/**
 * A webhook delivery failed verification. $errorCode is one of missing_secret,
 * missing_header, invalid_timestamp, signature_expired, invalid_signature or
 * invalid_payload.
 */
final class WebhookVerificationException extends WoyraException
{
    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message, 0, $errorCode);
    }
}
