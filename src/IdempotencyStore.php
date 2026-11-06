<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class IdempotencyStore
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    public function put(string $key, array $value): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new InstantConnectException('Unable to create the idempotency storage directory.');
        }

        $path = $this->path($key);
        $temporaryPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false || !rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new InstantConnectException('Unable to persist the idempotent tool result.');
        }
        chmod($path, 0600);
    }

    /**
     * Serializes calls for one idempotency key and caches successful results.
     *
     * @param callable():array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function once(string $key, callable $operation): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new InstantConnectException('Unable to create the idempotency storage directory.');
        }

        $lock = fopen($this->path($key) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new InstantConnectException('Unable to lock the idempotent tool request.');
        }

        try {
            $cached = $this->get($key);
            if ($cached !== null) {
                $cached['reused'] = true;
                return $cached;
            }

            $result = $operation();
            if (($result['sms_sent'] ?? false) === true) {
                $this->put($key, $result);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
