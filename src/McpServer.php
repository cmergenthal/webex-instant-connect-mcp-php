<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class McpServer
{
    public function __construct(
        private readonly string $expectedToken,
        private readonly string $tokenHeaderName,
        private readonly HeaderBag $headers,
        private readonly MeetingInviteService $inviteService
    ) {
    }

    public function handleHttpRequest(): void
    {
        $parsedPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($parsedPath) ? rtrim($parsedPath, '/') : '/';
        if (str_ends_with($path, '/api/v1/instant-connect-invitations')) {
            $this->handleRestHttpRequest();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->sendJson(['error' => 'Method not allowed. Send MCP JSON-RPC requests with POST.'], 405);
            return;
        }

        if (!$this->isAuthorized()) {
            $this->sendJson([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => sprintf('Unauthorized. Supply the token in the %s header.', $this->tokenHeaderName),
                ],
            ], 401);
            return;
        }

        $request = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($request)) {
            $this->sendJson($this->errorResponse(null, -32700, 'Parse error'), 400);
            return;
        }

        $response = $this->dispatch($request);
        if ($response === null) {
            http_response_code(202);
            return;
        }

        $this->sendJson($response, 200);
    }

    private function isAuthorized(): bool
    {
        $supplied = $this->headers->get($this->tokenHeaderName);
        if ($supplied === null) {
            $supplied = $this->headers->get('X-Instant-Connect-API-Token');
        }
        if ($supplied === null) {
            $supplied = $this->headers->get('Authorization');
        }
        if ($supplied === null || $this->expectedToken === '') {
            return false;
        }

        if (str_starts_with(strtolower($supplied), 'bearer ')) {
            $supplied = trim(substr($supplied, 7));
        }

        return hash_equals($this->expectedToken, $supplied);
    }

    private function handleRestHttpRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->sendJson([
                'status' => 'error',
                'error' => ['code' => 'method_not_allowed', 'message' => 'Send invitation requests with POST.'],
            ], 405);
            return;
        }

        if (!$this->isAuthorized()) {
            $this->sendJson([
                'status' => 'error',
                'error' => ['code' => 'unauthorized', 'message' => 'Supply a valid API token.'],
            ], 401);
            return;
        }

        $arguments = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($arguments)) {
            $this->sendJson([
                'status' => 'error',
                'error' => ['code' => 'invalid_json', 'message' => 'The request body must be a JSON object.'],
            ], 400);
            return;
        }

        $response = $this->dispatchRest($arguments);
        $this->sendJson($response['body'], $response['status']);
    }

    /**
     * Public for transport-independent testing and embedding.
     *
     * @param array<string, mixed> $arguments
     * @return array{status:int, body:array<string, mixed>}
     */
    public function dispatchRest(array $arguments): array
    {
        $validationError = $this->validateArguments($arguments);
        if ($validationError !== null) {
            return [
                'status' => 400,
                'body' => [
                    'status' => 'error',
                    'error' => ['code' => 'invalid_request', 'message' => $validationError],
                ],
            ];
        }

        try {
            $result = $this->inviteService->createAndSend($arguments);
        } catch (InstantConnectException $exception) {
            return [
                'status' => 502,
                'body' => [
                    'status' => 'error',
                    'error' => ['code' => 'upstream_error', 'message' => $exception->getMessage()],
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 500,
                'body' => [
                    'status' => 'error',
                    'error' => ['code' => 'internal_error', 'message' => 'Internal server error.'],
                ],
            ];
        }

        if (($result['work_item_created'] ?? false) !== true || ($result['sms_sent'] ?? false) !== true) {
            return [
                'status' => 502,
                'body' => [
                    'status' => 'partial_failure',
                    'data' => $result,
                    'error' => [
                        'code' => ($result['work_item_created'] ?? false) !== true ? 'work_item_failed' : 'sms_failed',
                        'message' => (string) ($result['work_item_error'] ?? $result['sms_error'] ?? 'The post-meeting workflow failed.'),
                    ],
                ],
            ];
        }

        return [
            'status' => ($result['reused'] ?? false) === true ? 200 : 201,
            'body' => ['status' => 'success', 'data' => $result],
        ];
    }

    /** @param array<string, mixed> $request */
    public function dispatch(array $request): ?array
    {
        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        if (!is_string($method)) {
            return $this->errorResponse($id, -32600, 'Invalid Request');
        }

        try {
            return match ($method) {
                'initialize' => $this->successResponse($id, [
                    'protocolVersion' => '2025-03-26',
                    'serverInfo' => ['name' => 'webex-instant-connect-mcp-php', 'version' => '1.0.0'],
                    'capabilities' => ['tools' => new \stdClass()],
                ]),
                'ping' => $this->successResponse($id, ['pong' => true]),
                'notifications/initialized' => null,
                'tools/list' => $this->toolsListResponse($id),
                'tools/call' => $this->toolsCallResponse($id, is_array($params) ? $params : []),
                default => $this->errorResponse($id, -32601, 'Method not found'),
            };
        } catch (InstantConnectException $exception) {
            return $this->errorResponse($id, $exception->jsonRpcCode(), $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->errorResponse($id, -32603, 'Internal server error.');
        }
    }

    private function toolsListResponse(mixed $id): array
    {
        return $this->successResponse($id, [
            'tools' => [[
                'name' => 'create_instant_connect_meeting_and_sms_caller',
                'title' => 'Create Instant Connect Meeting and SMS Caller',
                'description' => 'Creates secure Webex Instant Connect host and guest links, creates a Webex Contact Center work item containing the host URL, sends only the guest link to the caller by SMS, and returns the host URL. Call once per interaction and pass interaction_id to make retries safe.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['caller_phone_number'],
                    'properties' => [
                        'caller_phone_number' => [
                            'type' => 'string',
                            'pattern' => '^\\+[1-9][0-9]{7,14}$',
                            'description' => 'Caller mobile number in E.164 format, for example +13125550123.',
                        ],
                        'interaction_id' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 200,
                            'description' => 'Recommended Webex Contact Center interaction or call ID. Reusing it with the same phone number returns the prior result without sending another SMS.',
                        ],
                        'meeting_subject' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 160,
                            'description' => 'Optional meeting title. Defaults to Webex Instant Connect.',
                        ],
                        'expiration_minutes' => [
                            'type' => 'integer',
                            'minimum' => 5,
                            'maximum' => 1440,
                            'default' => 30,
                            'description' => 'Number of minutes the generated links remain valid.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'required' => ['host_url', 'guest_url', 'meeting_subject', 'expires_at', 'caller_phone_number', 'work_item_created', 'sms_sent', 'reused'],
                    'properties' => [
                        'host_url' => ['type' => 'string'],
                        'guest_url' => ['type' => 'string'],
                        'meeting_subject' => ['type' => 'string'],
                        'expires_at' => ['type' => 'string'],
                        'caller_phone_number' => ['type' => 'string'],
                        'work_item_created' => ['type' => 'boolean'],
                        'work_item_id' => ['type' => ['string', 'null']],
                        'work_item_error' => ['type' => 'string'],
                        'sms_sent' => ['type' => 'boolean'],
                        'sms_message_id' => ['type' => ['string', 'null']],
                        'sms_error' => ['type' => 'string'],
                        'reused' => ['type' => 'boolean'],
                    ],
                    'additionalProperties' => false,
                ],
            ]],
        ]);
    }

    /** @param array<string, mixed> $params */
    private function toolsCallResponse(mixed $id, array $params): array
    {
        if (($params['name'] ?? null) !== 'create_instant_connect_meeting_and_sms_caller') {
            return $this->errorResponse($id, -32602, 'Unknown tool');
        }

        $arguments = $params['arguments'] ?? null;
        if (!is_array($arguments)) {
            return $this->errorResponse($id, -32602, 'Tool arguments must be an object.');
        }

        $validationError = $this->validateArguments($arguments);
        if ($validationError !== null) {
            return $this->errorResponse($id, -32602, $validationError);
        }

        $result = $this->inviteService->createAndSend($arguments);
        $workItemCreated = ($result['work_item_created'] ?? false) === true;
        $smsSent = ($result['sms_sent'] ?? false) === true;
        $text = $workItemCreated && $smsSent
            ? sprintf("Work item created and SMS invitation sent. Host URL for the agent: %s", $result['host_url'])
            : sprintf(
                "Meeting created, but the follow-up workflow failed: %s Host URL: %s",
                $result['work_item_error'] ?? $result['sms_error'] ?? 'unknown error.',
                $result['host_url']
            );

        return $this->successResponse($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $result,
            'isError' => !$workItemCreated || !$smsSent,
        ]);
    }

    /** @param array<string, mixed> $arguments */
    private function validateArguments(array $arguments): ?string
    {
        $allowed = ['caller_phone_number', 'interaction_id', 'meeting_subject', 'expiration_minutes'];
        foreach (array_keys($arguments) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return 'Unknown argument: ' . (string) $key;
            }
        }

        $phone = $arguments['caller_phone_number'] ?? null;
        if (!is_string($phone) || preg_match('/^\+[1-9][0-9]{7,14}$/', trim($phone)) !== 1) {
            return 'caller_phone_number must be a valid E.164 number, for example +13125550123.';
        }

        foreach (['interaction_id' => 200, 'meeting_subject' => 160] as $key => $maxLength) {
            if (!isset($arguments[$key])) {
                continue;
            }
            if (!is_string($arguments[$key]) || trim($arguments[$key]) === '' || strlen($arguments[$key]) > $maxLength) {
                return sprintf('%s must be a non-empty string no longer than %d characters.', $key, $maxLength);
            }
        }

        if (isset($arguments['expiration_minutes'])) {
            if (!is_int($arguments['expiration_minutes']) || $arguments['expiration_minutes'] < 5 || $arguments['expiration_minutes'] > 1440) {
                return 'expiration_minutes must be an integer from 5 through 1440.';
            }
        }

        return null;
    }

    private function successResponse(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param array<string, mixed> $payload */
    private function sendJson(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
