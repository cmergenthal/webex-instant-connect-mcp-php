<?php

declare(strict_types=1);

return [
    'server' => [
        'api_token' => 'replace-with-a-long-random-secret',
        'token_header' => 'X-Instant-Connect-MCP-Token',
        'idempotency_dir' => __DIR__ . '/var/idempotency',
    ],
    'webex' => [
        'access_token' => 'replace-with-your-webex-bot-token',
        'endpoint' => 'https://mtg-broker-a.wbx2.com/api/v2/joseencrypt',
        'audience' => 'a4d886b0-979f-4e2c-a958-3e8c14605e51',
    ],
    'sms' => [
        'endpoint' => 'https://hooks.us.webexconnect.io/events/YOUR_EVENT_ID',
        'service_key' => '', // Optional Authorization header value, if required by the webhook.
    ],
    'work_item' => [
        'endpoint' => 'https://middleware.example.com/create-work-item-task/index.php',
        'middleware_key' => 'replace-with-your-middleware-key',
    ],
];
