<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

interface JsonHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status:int, headers:array<string, string>, body:array<string, mixed>}
     */
    public function post(string $url, array $headers, array $payload): array;
}
