<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/InstantConnectException.php';
require_once __DIR__ . '/../src/HeaderBag.php';
require_once __DIR__ . '/../src/JsonHttpClient.php';
require_once __DIR__ . '/../src/InstantConnectClient.php';
require_once __DIR__ . '/../src/WorkItemClient.php';
require_once __DIR__ . '/../src/WebexConnectSmsClient.php';
require_once __DIR__ . '/../src/IdempotencyStore.php';
require_once __DIR__ . '/../src/MeetingInviteService.php';
require_once __DIR__ . '/../src/McpServer.php';

use WebexInstantConnectMcp\HeaderBag;
use WebexInstantConnectMcp\IdempotencyStore;
use WebexInstantConnectMcp\InstantConnectClient;
use WebexInstantConnectMcp\InstantConnectException;
use WebexInstantConnectMcp\JsonHttpClient;
use WebexInstantConnectMcp\McpServer;
use WebexInstantConnectMcp\MeetingInviteService;
use WebexInstantConnectMcp\WebexConnectSmsClient;
use WebexInstantConnectMcp\WorkItemClient;

final class FakeHttpClient implements JsonHttpClient
{
    /** @var list<array<string, mixed>|Throwable> */
    private array $responses;
    /** @var list<array{url:string, headers:array<string, string>, payload:array<string, mixed>}> */
    public array $requests = [];

    /** @param list<array<string, mixed>|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, array $payload): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (!is_array($response)) {
            throw new RuntimeException('No fake response configured.');
        }
        /** @var array{status:int, headers:array<string, string>, body:array<string, mixed>} $response */
        return $response;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function fakeMeetingResponse(): array
{
    return [
        'status' => 200,
        'headers' => [],
        'body' => [
            'host' => [['cipher' => 'host-cipher', 'short' => 'host123']],
            'guest' => [['cipher' => 'guest-cipher', 'short' => 'guest456']],
            'baseUrl' => 'https://instant.webex.com/visit/',
        ],
    ];
}

assertSameValue(
    'Bearer test-token',
    (new HeaderBag(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-token']))->get('Authorization'),
    'Reads Authorization forwarded by Apache/FastCGI.'
);

function makeServer(FakeHttpClient $http, string $storeDirectory): McpServer
{
    $service = new MeetingInviteService(
        new InstantConnectClient($http, 'webex-token'),
        new WorkItemClient(
            $http,
            'https://middleware.example.com/create-work-item-task/index.php',
            'replace-with-your-middleware-key',
            [
                'originId' => 'cmergent@cisco.com',
                'originName' => 'Telehealth Request',
                'workItemData' => [
                    'firstName' => 'Chase',
                    'lastName' => 'Mergenthal',
                    'location' => 'Iowa',
                    'companyName' => 'ACME Foods',
                ],
                'flowSettings' => ['source' => 'web'],
                'globalVariables' => ['caseType' => 'Telehealth Request'],
            ]
        ),
        new WebexConnectSmsClient($http, 'https://hooks.us.webexconnect.io/events/YOUR_EVENT_ID'),
        new IdempotencyStore($storeDirectory)
    );

    return new McpServer('mcp-token', 'X-Test-Token', new HeaderBag([]), $service);
}

$testDirectory = sys_get_temp_dir() . '/instant-connect-mcp-tests-' . bin2hex(random_bytes(5));

$http = new FakeHttpClient([
    fakeMeetingResponse(),
    ['status' => 201, 'headers' => [], 'body' => ['data' => ['id' => 'work-item-123']]],
    ['status' => 202, 'headers' => [], 'body' => ['messageId' => 'sms-123']],
]);
$server = makeServer($http, $testDirectory);
$call = [
    'jsonrpc' => '2.0',
    'id' => 7,
    'method' => 'tools/call',
    'params' => [
        'name' => 'create_instant_connect_meeting_and_sms_caller',
        'arguments' => [
            'caller_phone_number' => '+13125550123',
            'interaction_id' => 'interaction-abc',
            'meeting_subject' => 'Support call',
        ],
    ],
];
$response = $server->dispatch($call);
$result = $response['result']['structuredContent'] ?? [];
assertSameValue('https://instant.webex.com/visit/host123', $result['host_url'] ?? null, 'Returns the host short URL.');
assertSameValue(true, $result['work_item_created'] ?? null, 'Marks the work item created.');
assertSameValue('work-item-123', $result['work_item_id'] ?? null, 'Returns the work item id.');
assertSameValue(true, $result['sms_sent'] ?? null, 'Marks SMS sent.');
assertSameValue('sms-123', $result['sms_message_id'] ?? null, 'Returns SMS message id.');
assertSameValue(
    'https://hooks.us.webexconnect.io/events/YOUR_EVENT_ID',
    $http->requests[2]['url'],
    'Calls the configured Webex Connect event webhook.'
);
assertSameValue(
    ['number' => '+13125550123', 'url' => 'https://instant.webex.com/visit/guest456'],
    $http->requests[2]['payload'],
    'Posts the required number and guest URL webhook body.'
);
assertSameValue(false, str_contains($http->requests[2]['payload']['url'], 'host123'), 'Never sends the host URL to the webhook.');
assertSameValue(
    'https://middleware.example.com/create-work-item-task/index.php',
    $http->requests[1]['url'],
    'Calls the configured work-item endpoint.'
);
assertSameValue('replace-with-your-middleware-key', $http->requests[1]['headers']['X-Middleware-Key'] ?? null, 'Sends the work-item middleware key.');
assertSameValue(
    'https://instant.webex.com/visit/host123',
    $http->requests[1]['payload']['workItemData']['demoURL'] ?? null,
    'Places the host URL in workItemData.demoURL.'
);
assertSameValue('cmergent@cisco.com', $http->requests[1]['payload']['originId'] ?? null, 'Sends the configured origin id.');
assertSameValue('Telehealth Request', $http->requests[1]['payload']['globalVariables']['caseType'] ?? null, 'Sends the configured case type.');

$repeat = $server->dispatch($call);
assertSameValue(true, $repeat['result']['structuredContent']['reused'] ?? null, 'Marks an idempotent replay.');
assertSameValue(3, count($http->requests), 'Does not call any upstream service again on replay.');

$failureDirectory = $testDirectory . '-failure';
$failedHttp = new FakeHttpClient([
    fakeMeetingResponse(),
    ['status' => 201, 'headers' => [], 'body' => ['data' => ['id' => 'work-item-456']]],
    new InstantConnectException('SMS provider unavailable.', -32603, 502),
]);
$failedResponse = makeServer($failedHttp, $failureDirectory)->dispatch([
    'jsonrpc' => '2.0',
    'id' => 8,
    'method' => 'tools/call',
    'params' => [
        'name' => 'create_instant_connect_meeting_and_sms_caller',
        'arguments' => ['caller_phone_number' => '+13125550124'],
    ],
]);
assertSameValue(true, $failedResponse['result']['isError'] ?? null, 'Returns an MCP tool error when SMS fails.');
assertSameValue('https://instant.webex.com/visit/host123', $failedResponse['result']['structuredContent']['host_url'] ?? null, 'Preserves host URL on partial failure.');
assertSameValue(false, $failedResponse['result']['structuredContent']['sms_sent'] ?? null, 'Reports SMS failure truthfully.');
assertSameValue(true, $failedResponse['result']['structuredContent']['work_item_created'] ?? null, 'Preserves successful work-item status when SMS fails.');

$workItemFailureHttp = new FakeHttpClient([
    fakeMeetingResponse(),
    new InstantConnectException('Work-item provider unavailable.', -32603, 502),
]);
$workItemFailure = makeServer($workItemFailureHttp, $testDirectory . '-work-item-failure')->dispatchRest([
    'caller_phone_number' => '+13125550126',
]);
assertSameValue(502, $workItemFailure['status'], 'REST reports a work-item partial failure.');
assertSameValue('work_item_failed', $workItemFailure['body']['error']['code'] ?? null, 'Returns a stable work-item error code.');
assertSameValue(false, $workItemFailure['body']['data']['work_item_created'] ?? null, 'Reports work-item failure truthfully.');
assertSameValue(2, count($workItemFailureHttp->requests), 'Does not attempt SMS after work-item failure.');

$invalid = $server->dispatch([
    'jsonrpc' => '2.0',
    'id' => 9,
    'method' => 'tools/call',
    'params' => [
        'name' => 'create_instant_connect_meeting_and_sms_caller',
        'arguments' => ['caller_phone_number' => '312-555-0123'],
    ],
]);
assertSameValue(-32602, $invalid['error']['code'] ?? null, 'Rejects a non-E.164 number.');

$restDirectory = $testDirectory . '-rest';
$restHttp = new FakeHttpClient([
    fakeMeetingResponse(),
    ['status' => 201, 'headers' => [], 'body' => ['data' => ['id' => 'work-item-rest-1']]],
    ['status' => 202, 'headers' => [], 'body' => ['messageId' => 'sms-rest-1']],
]);
$restServer = makeServer($restHttp, $restDirectory);
$restResponse = $restServer->dispatchRest([
    'caller_phone_number' => '+13125550125',
    'interaction_id' => 'rest-interaction-1',
    'meeting_subject' => 'REST support call',
]);
assertSameValue(201, $restResponse['status'], 'REST creates a new invitation with HTTP 201.');
assertSameValue('success', $restResponse['body']['status'] ?? null, 'REST reports success.');
assertSameValue(
    'https://instant.webex.com/visit/host123',
    $restResponse['body']['data']['host_url'] ?? null,
    'REST returns the host URL as JSON.'
);
assertSameValue(
    ['number' => '+13125550125', 'url' => 'https://instant.webex.com/visit/guest456'],
    $restHttp->requests[2]['payload'],
    'REST sends the same Webex Connect webhook payload.'
);

$restReplay = $restServer->dispatchRest([
    'caller_phone_number' => '+13125550125',
    'interaction_id' => 'rest-interaction-1',
    'meeting_subject' => 'REST support call',
]);
assertSameValue(200, $restReplay['status'], 'REST replay returns HTTP 200.');
assertSameValue(true, $restReplay['body']['data']['reused'] ?? null, 'REST replay is marked reused.');
assertSameValue(3, count($restHttp->requests), 'REST replay does not call upstream services.');

$restInvalid = $restServer->dispatchRest(['caller_phone_number' => 'invalid']);
assertSameValue(400, $restInvalid['status'], 'REST validation errors return HTTP 400.');
assertSameValue('invalid_request', $restInvalid['body']['error']['code'] ?? null, 'REST returns a stable validation error code.');

echo "All tests passed.\n";
