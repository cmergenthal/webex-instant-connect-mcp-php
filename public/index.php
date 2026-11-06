<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/InstantConnectException.php';
require_once __DIR__ . '/../src/HeaderBag.php';
require_once __DIR__ . '/../src/JsonHttpClient.php';
require_once __DIR__ . '/../src/HttpJsonClient.php';
require_once __DIR__ . '/../src/InstantConnectClient.php';
require_once __DIR__ . '/../src/WorkItemClient.php';
require_once __DIR__ . '/../src/WebexConnectSmsClient.php';
require_once __DIR__ . '/../src/IdempotencyStore.php';
require_once __DIR__ . '/../src/MeetingInviteService.php';
require_once __DIR__ . '/../src/McpServer.php';

use WebexInstantConnectMcp\HeaderBag;
use WebexInstantConnectMcp\HttpJsonClient;
use WebexInstantConnectMcp\IdempotencyStore;
use WebexInstantConnectMcp\InstantConnectClient;
use WebexInstantConnectMcp\McpServer;
use WebexInstantConnectMcp\MeetingInviteService;
use WebexInstantConnectMcp\WebexConnectSmsClient;
use WebexInstantConnectMcp\WorkItemClient;

$configFile = __DIR__ . '/../config.php';
$config = is_file($configFile) ? require $configFile : [];
$config = is_array($config) ? $config : [];

$value = static function (string $environmentName, array $path, string $default = '') use ($config): string {
    $environmentValue = getenv($environmentName);
    if (is_string($environmentValue) && trim($environmentValue) !== '') {
        return trim($environmentValue);
    }

    $current = $config;
    foreach ($path as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }
        $current = $current[$segment];
    }

    return is_string($current) ? trim($current) : $default;
};

$mcpToken = $value('INSTANT_CONNECT_API_TOKEN', ['server', 'api_token']);
if ($mcpToken === '') {
    $mcpToken = $value('INSTANT_CONNECT_MCP_API_TOKEN', ['server', 'api_token']);
}
$mcpHeader = $value('INSTANT_CONNECT_MCP_TOKEN_HEADER', ['server', 'token_header'], 'X-Instant-Connect-MCP-Token');
$webexToken = $value('WEBEX_INSTANT_CONNECT_TOKEN', ['webex', 'access_token']);
$meetingEndpoint = $value('WEBEX_INSTANT_CONNECT_ENDPOINT', ['webex', 'endpoint'], 'https://mtg-broker-a.wbx2.com/api/v2/joseencrypt');
$audience = $value('WEBEX_INSTANT_CONNECT_AUDIENCE', ['webex', 'audience'], 'a4d886b0-979f-4e2c-a958-3e8c14605e51');
$smsEndpoint = $value(
    'WEBEX_CONNECT_SMS_ENDPOINT',
    ['sms', 'endpoint']
);
$smsServiceKey = $value('WEBEX_CONNECT_SERVICE_KEY', ['sms', 'service_key']);
$workItemEndpoint = $value(
    'WORK_ITEM_ENDPOINT',
    ['work_item', 'endpoint']
);
$workItemMiddlewareKey = $value('WORK_ITEM_MIDDLEWARE_KEY', ['work_item', 'middleware_key']);
$storeDirectory = $value('INSTANT_CONNECT_IDEMPOTENCY_DIR', ['server', 'idempotency_dir'], __DIR__ . '/../var/idempotency');

$missing = [];
foreach ([
    'INSTANT_CONNECT_API_TOKEN' => $mcpToken,
    'WEBEX_INSTANT_CONNECT_TOKEN' => $webexToken,
    'WEBEX_CONNECT_SMS_ENDPOINT' => $smsEndpoint,
    'WORK_ITEM_ENDPOINT' => $workItemEndpoint,
    'WORK_ITEM_MIDDLEWARE_KEY' => $workItemMiddlewareKey,
] as $name => $configuredValue) {
    if ($configuredValue === '') {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required configuration: ' . implode(', ', $missing)], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$http = new HttpJsonClient();
$service = new MeetingInviteService(
    new InstantConnectClient($http, $webexToken, $meetingEndpoint, $audience),
    new WorkItemClient(
        $http,
        $workItemEndpoint,
        $workItemMiddlewareKey,
        [
            'originId' => 'cmergent@cisco.com',
            'originName' => 'Telehealth Request',
            'workItemData' => [
                'firstName' => 'Chase',
                'lastName' => 'Mergenthal',
                'location' => 'Iowa',
                'companyName' => 'Telehealth Request',
            ],
            'flowSettings' => ['source' => 'web'],
            'globalVariables' => ['caseType' => 'Telehealth Request'],
        ]
    ),
    new WebexConnectSmsClient($http, $smsEndpoint, $smsServiceKey),
    new IdempotencyStore($storeDirectory)
);

(new McpServer($mcpToken, $mcpHeader, new HeaderBag(), $service))->handleHttpRequest();
