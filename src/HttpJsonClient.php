<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class HttpJsonClient implements JsonHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status:int, headers:array<string, string>, body:array<string, mixed>}
     */
    public function post(string $url, array $headers, array $payload): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new InstantConnectException('Unable to initialize the HTTP client.');
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $responseHeaders = [];
        $httpHeaders = ['Accept: application/json', 'Content-Type: application/json'];

        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);

        $rawBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($rawBody === false) {
            throw new InstantConnectException('Upstream request failed: ' . ($curlError ?: 'unknown cURL error'), -32603, 502);
        }

        $body = $rawBody === '' ? [] : json_decode($rawBody, true);
        if ($rawBody !== '' && !is_array($body)) {
            throw new InstantConnectException('Upstream service returned a non-JSON response.', -32603, 502);
        }

        if ($status < 200 || $status >= 300) {
            throw new InstantConnectException(
                sprintf('Upstream service returned HTTP %d: %s', $status, $this->errorMessage($body ?: [], $rawBody)),
                -32603,
                502
            );
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body ?: []];
    }

    /** @param array<string, mixed> $body */
    private function errorMessage(array $body, string $rawBody): string
    {
        foreach (['message', 'error_description', 'error', 'description'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return substr($body[$key], 0, 500);
            }
        }

        return $rawBody !== '' ? substr($rawBody, 0, 500) : 'empty response';
    }
}
