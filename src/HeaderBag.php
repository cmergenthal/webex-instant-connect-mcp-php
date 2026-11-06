<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class HeaderBag
{
    /** @param array<string, mixed>|null $server */
    public function __construct(private readonly ?array $server = null)
    {
    }

    public function get(string $headerName): ?string
    {
        $source = $this->server ?? $_SERVER;
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        $value = $source[$serverKey] ?? null;

        if (!is_string($value) && strcasecmp($headerName, 'Authorization') === 0) {
            $value = $source['REDIRECT_HTTP_AUTHORIZATION'] ?? $source['AUTHORIZATION'] ?? null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
