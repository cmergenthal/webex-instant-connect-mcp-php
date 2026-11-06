<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class WorkItemClient
{
    /** @param array<string, mixed> $template */
    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $endpoint,
        private readonly string $middlewareKey,
        private readonly array $template
    ) {
    }

    /** @return array{created:bool, work_item_id:?string, provider_response:array<string, mixed>} */
    public function create(string $hostUrl): array
    {
        $payload = $this->template;
        $workItemData = $payload['workItemData'] ?? [];
        if (!is_array($workItemData)) {
            $workItemData = [];
        }
        $workItemData['demoURL'] = $hostUrl;
        $payload['workItemData'] = $workItemData;

        $response = $this->http->post(
            $this->endpoint,
            ['X-Middleware-Key' => $this->middlewareKey],
            $payload
        );

        return [
            'created' => true,
            'work_item_id' => $this->workItemId($response['body']),
            'provider_response' => $response['body'],
        ];
    }

    /** @param array<string, mixed> $body */
    private function workItemId(array $body): ?string
    {
        foreach ([$body['data']['id'] ?? null, $body['id'] ?? null, $body['workItemId'] ?? null] as $candidate) {
            if (is_string($candidate) || is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
