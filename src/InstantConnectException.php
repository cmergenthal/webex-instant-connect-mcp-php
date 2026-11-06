<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

use RuntimeException;

final class InstantConnectException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $jsonRpcCode = -32603,
        private readonly int $httpStatusCode = 500
    ) {
        parent::__construct($message);
    }

    public function jsonRpcCode(): int
    {
        return $this->jsonRpcCode;
    }

    public function httpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
