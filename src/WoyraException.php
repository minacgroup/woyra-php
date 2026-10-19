<?php

declare(strict_types=1);

namespace Woyra;

/**
 * Every error the SDK throws is a WoyraException, so one catch handles them all.
 *
 * $status is the HTTP status (0 when no response was received). $errorCode is
 * stable and machine-readable: branch on it, never on the message.
 */
class WoyraException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'unknown_error',
        public readonly ?string $param = null,
        public readonly ?string $requestId = null,
        public readonly ?string $docUrl = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getParam(): ?string
    {
        return $this->param;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }
}
