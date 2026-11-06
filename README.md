# Webex Instant Connect + SMS MCP and REST Server

This plain PHP MCP server is designed for a Webex Contact Center AI Agent. One tool performs the complete handoff:

1. Creates one Webex Instant Connect host URL and one guest URL.
2. Creates a Webex Contact Center work item with the host URL in `workItemData.demoURL`.
3. Sends the guest URL to the caller with Webex Connect SMS.
4. Returns the host URL to the AI Agent in both MCP text and structured output.

The host URL is never included in the SMS payload.

The same workflow is also available as a conventional REST API for AI agents that do not support MCP.

## Tool

`create_instant_connect_meeting_and_sms_caller`

Required input:

- `caller_phone_number`: E.164 mobile number, such as `+13125550123`

Recommended input:

- `interaction_id`: the Webex Contact Center interaction ID. Repeated calls with the same interaction ID and phone number return the saved successful result without creating another meeting or sending another SMS.

Optional inputs:

- `meeting_subject`: defaults to `Webex Instant Connect`
- `expiration_minutes`: 5 through 1,440; defaults to 30

Successful structured output includes `host_url`, `guest_url`, `expires_at`, `work_item_created`, `work_item_id`, `sms_sent`, `sms_message_id`, and `reused`. If a follow-up operation fails, the tool returns `isError: true` and preserves the host URL for troubleshooting.

## Requirements

- PHP 8.1 or newer with cURL
- Webex Instant Connect activated by an administrator at `https://instant.webex.com/integrate/`
- A Webex bot token or other token accepted by the Instant Connect meeting-link API
- The configured Webex Connect event webhook
- Access to the configured Webex Contact Center work-item middleware
- HTTPS when deployed

## Configuration

Use environment variables in production:

```bash
export INSTANT_CONNECT_API_TOKEN='replace-with-a-long-random-secret'
export WEBEX_INSTANT_CONNECT_TOKEN='replace-with-webex-bot-token'
export WEBEX_CONNECT_SMS_ENDPOINT='https://hooks.us.webexconnect.io/events/YOUR_EVENT_ID'
export WORK_ITEM_ENDPOINT='https://middleware.example.com/create-work-item-task/index.php'
export WORK_ITEM_MIDDLEWARE_KEY='replace-with-your-middleware-key'
php -S 127.0.0.1:8084 -t public public/router.php
```

The SMS endpoint, work-item endpoint, and middleware key are required; there are no deployment-specific defaults. Replace the example URLs and credentials with your own.

Optional variables:

- `INSTANT_CONNECT_MCP_TOKEN_HEADER` (default `X-Instant-Connect-MCP-Token`)
- `INSTANT_CONNECT_MCP_API_TOKEN` (backward-compatible alias for `INSTANT_CONNECT_API_TOKEN`)
- `WEBEX_INSTANT_CONNECT_ENDPOINT`
- `WEBEX_INSTANT_CONNECT_AUDIENCE`
- `WEBEX_CONNECT_SERVICE_KEY` (optional Authorization header value)
- `INSTANT_CONNECT_IDEMPOTENCY_DIR`

For local testing, copy `config.example.php` to `config.php` and fill in its values. Set your web server document root to `public/` so configuration and stored meeting links are outside the web root. `config.php` and persisted idempotency results are gitignored.

The server posts the following body to the Webex Connect event webhook. `url` is always the Instant Connect guest URL:

```json
{
  "number": "+13125550123",
  "url": "https://instant.webex.com/visit/example"
}
```

After generating the meeting and before sending the SMS, the server posts the following to the work-item middleware with `X-Middleware-Key: replace-with-your-middleware-key`. `demoURL` is populated dynamically with the Instant Connect host URL:

```json
{
  "originId": "cmergent@cisco.com",
  "originName": "Telehealth Request",
  "workItemData": {
    "demoURL": "https://instant.webex.com/visit/host-example",
    "firstName": "Chase",
    "lastName": "Mergenthal",
    "location": "Iowa",
    "companyName": "ACME Foods"
  },
  "flowSettings": {
    "source": "web"
  },
  "globalVariables": {
    "caseType": "Telehealth Request"
  }
}
```

## MCP requests

Initialize:

```bash
curl -X POST http://127.0.0.1:8084 \
  -H 'Content-Type: application/json' \
  -H 'X-Instant-Connect-MCP-Token: replace-with-a-long-random-secret' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}'
```

## REST API

Send a `POST` request to:

```text
https://your-server.example/api/v1/instant-connect-invitations
```

Authenticate with either the configured custom header or a bearer token:

```text
X-Instant-Connect-MCP-Token: your-api-token
```

or:

```text
Authorization: Bearer your-api-token
```

Request:

```bash
curl -X POST 'https://your-server.example/api/v1/instant-connect-invitations' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer replace-with-a-long-random-secret' \
  -d '{
    "caller_phone_number": "+13125550123",
    "interaction_id": "contact-center-interaction-id",
    "meeting_subject": "Customer video support",
    "expiration_minutes": 30
  }'
```

Successful response (`201 Created`, or `200 OK` for an idempotent replay):

```json
{
  "status": "success",
  "data": {
    "host_url": "https://instant.webex.com/visit/host-example",
    "guest_url": "https://instant.webex.com/visit/guest-example",
    "meeting_subject": "Customer video support - 1234567890",
    "expires_at": "2026-09-16T18:30:00+00:00",
    "caller_phone_number": "+13125550123",
    "work_item_created": true,
    "work_item_id": "example-work-item-id",
    "sms_sent": true,
    "sms_message_id": "example-message-id",
    "reused": false
  }
}
```

If the meeting is created but work-item creation fails, the API returns `502` with `status: partial_failure`, `work_item_created: false`, and the generated host URL under `data`; SMS is not attempted. If the work item succeeds but the Webex Connect webhook fails, the API returns `502` with `work_item_created: true` and `sms_sent: false`.

Call the tool:

```bash
curl -X POST http://127.0.0.1:8084 \
  -H 'Content-Type: application/json' \
  -H 'X-Instant-Connect-MCP-Token: replace-with-a-long-random-secret' \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/call",
    "params": {
      "name": "create_instant_connect_meeting_and_sms_caller",
      "arguments": {
        "caller_phone_number": "+13125550123",
        "interaction_id": "webex-cc-interaction-id",
        "meeting_subject": "Customer video support"
      }
    }
  }'
```

## Webex Contact Center AI Agent instructions

Deploy the endpoint at a public HTTPS URL, then:

1. In the Webex Developer Portal, register an Agentic App for the MCP server. Select **Streamable HTTP**, enter the public endpoint URL, and choose **Custom Header based Auth** (or API key, if that matches your deployment).
2. Use `X-Instant-Connect-MCP-Token` as the custom header name and the configured MCP token as its value. The server accepts both a raw value and `Bearer <value>` for compatibility with Webex org-scoped token handling.
3. Have an administrator authorize the Agentic App in Control Hub.
4. In Webex AI Agent Studio, open the autonomous agent, go to **Actions**, add the published MCP tool, and verify that its inputs and output appear correctly.

Current official references: [onboard an MCP server](https://developer.webex.com/mcp/docs/onboard-your-mcp-server) and [configure an MCP client action](https://help.webex.com/article/ncs9r37).

Add an agent instruction similar to:

> When the caller agrees to a video meeting, call `create_instant_connect_meeting_and_sms_caller` once. Pass the caller's verified E.164 mobile number and the current interaction ID. Confirm success only when `sms_sent` is true. Give the returned `host_url` to the contact-center agent; never send the host URL to the caller.

Do not tell the caller that the invitation was sent when `isError` is true or `sms_sent` is false.

## Tests

```bash
php tests/run.php
```

The tests use fake upstream services; they do not create meetings or send SMS messages.

## Security notes

- Keep the API token, Webex token, Webex Connect service key, and work-item middleware key in a secret manager.
- Restrict access to the deployed endpoint and rotate credentials regularly.
- Treat host and guest URLs as credentials; do not log full tool request/response bodies.
- Obtain caller consent and follow applicable SMS registration, opt-in, and privacy requirements.
