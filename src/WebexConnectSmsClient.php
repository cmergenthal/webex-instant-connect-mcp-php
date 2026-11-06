<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class WebexConnectSmsClient
{
    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $endpoint,
        private readonly string $serviceKey = ''
    ) {
    }

    /** @return array{sent:bool, message_id:?string, provider_response:array<string, mixed>} */
    public function send(string $recipient, string $guestUrl): array
    {
        $headers = [];
        if ($this->serviceKey !== '') {
            $headers['Authorization'] = $this->serviceKey;
        }

        $response = $this->http->post(
            $this->endpoint,
            $headers,
            [
                'number' => $recipient,
                'url' => $guestUrl,
            ]
        );

        $body = $response['body'];

        return [
            'sent' => true,
            'message_id' => $this->messageId($body),
            'provider_response' => $body,
        ];
    }

    /** @param array<string, mixed> $body */
    private function messageId(array $body): ?string
    {
        foreach (['messageId', 'message_id', 'id', 'requestId', 'transid'] as $key) {
            if (isset($body[$key]) && (is_string($body[$key]) || is_int($body[$key]))) {
                return (string) $body[$key];
            }
        }

        if (isset($body['response'][0]) && is_array($body['response'][0])) {
            return $this->messageId($body['response'][0]);
        }

        return null;
    }
}
