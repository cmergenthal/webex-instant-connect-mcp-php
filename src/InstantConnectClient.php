<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class InstantConnectClient
{
    private const HOST_URL = 'https://instant.webex.com/gen/v1/login?int=jose&v=1&data=';
    private const GUEST_URL = 'https://instant.webex.com/gen/v1/talk?int=jose&v=1&data=';

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $accessToken,
        private readonly string $endpoint = 'https://mtg-broker-a.wbx2.com/api/v2/joseencrypt',
        private readonly string $audience = 'a4d886b0-979f-4e2c-a958-3e8c14605e51'
    ) {
    }

    /** @return array{host_url:string, guest_url:string, meeting_subject:string, expires_at:string} */
    public function createMeeting(string $meetingSubject, int $expirationMinutes): array
    {
        $now = time();
        $expires = $now + ($expirationMinutes * 60);
        $response = $this->http->post(
            $this->endpoint,
            ['Authorization' => 'Bearer ' . $this->accessToken],
            [
                'jwt' => [
                    'sub' => $meetingSubject,
                    'nbf' => $now - 30,
                    'exp' => $expires,
                ],
                'aud' => $this->audience,
                'numHost' => 1,
                'numGuest' => 1,
                'provideShortUrls' => true,
                'verticalType' => 'gen',
                'loginUrlForHost' => true,
            ]
        );

        $body = $response['body'];
        $host = $body['host'][0] ?? null;
        $guest = $body['guest'][0] ?? null;
        if (!is_array($host) || !is_array($guest)) {
            throw new InstantConnectException('Instant Connect did not return host and guest meeting links.', -32603, 502);
        }

        return [
            'host_url' => $this->meetingUrl($body, $host, self::HOST_URL),
            'guest_url' => $this->meetingUrl($body, $guest, self::GUEST_URL),
            'meeting_subject' => $meetingSubject,
            'expires_at' => gmdate('c', $expires),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $link
     */
    private function meetingUrl(array $body, array $link, string $longBaseUrl): string
    {
        if (isset($body['baseUrl'], $link['short']) && is_string($body['baseUrl']) && is_string($link['short'])) {
            return rtrim($body['baseUrl'], '/') . '/' . ltrim($link['short'], '/');
        }

        if (isset($link['cipher']) && is_string($link['cipher']) && $link['cipher'] !== '') {
            return $longBaseUrl . rawurlencode($link['cipher']);
        }

        throw new InstantConnectException('Instant Connect returned an invalid meeting link.', -32603, 502);
    }
}
