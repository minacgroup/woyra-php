<?php

declare(strict_types=1);

namespace Woyra;

/** No HTTP response was received (DNS, TCP, TLS or timeout), even after retries. */
final class ConnectionException extends WoyraException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, 'connection_error', null, null, null, $previous);
    }
}
