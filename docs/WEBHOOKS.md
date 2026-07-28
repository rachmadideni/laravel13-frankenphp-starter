# Webhook Module

Generic webhook receiver with HMAC signature verification, idempotency, and async queue processing.

## Overview

Safely receive and process webhooks from external systems:
- **HMAC-SHA256 verification** using `hash_equals` (timing-attack resistant)
- **Timestamp validation** (5-minute tolerance by default)
- **Idempotency** via unique keys to prevent duplicate processing
- **Async processing** via queue jobs (returns 202 immediately)
- **Raw payload storage** for debugging and replay

## Setup

### 1. Configure Webhook Secrets

Add webhook source secrets to `.env`:

```bash
WEBHOOK_GITHUB_SECRET=your-github-webhook-secret
WEBHOOK_STRIPE_SECRET=your-stripe-webhook-secret
WEBHOOK_EXTERNAL_API_SECRET=your-external-api-secret
```

Secrets are configured in `config/webhooks.php` per source.

### 2. Run Migration

```bash
php artisan migrate
```

Creates `webhook_payloads` table to store received payloads with status tracking.

### 3. Add Webhook Sources

Edit `config/webhooks.php` to add more sources:

```php
'sources' => [
    'my-service' => [
        'secret' => env('WEBHOOK_MY_SERVICE_SECRET', ''),
    ],
],
```

## Receiving Webhooks

### Endpoint

```
POST /api/webhook
```

### Required Headers

| Header | Description | Example |
|--------|-------------|---------|
| `X-Webhook-Source` | Source identifier | `github`, `stripe`, `my-service` |
| `X-Webhook-Signature` | HMAC-SHA256 of raw body | `sha256=abc123...` |
| `X-Webhook-Timestamp` | Unix timestamp | `1690123456` |
| `X-Idempotency-Key` | Unique request identifier | `github-push-123456` |

### Example Request (cURL)

```bash
#!/bin/bash

WEBHOOK_URL="https://example.com/api/webhook"
SECRET="your-webhook-secret"
TIMESTAMP=$(date +%s)
SOURCE="github"
IDEMPOTENCY_KEY="push-$(uuidgen)"
BODY='{"action":"push","repository":"test"}'

# Calculate HMAC signature
SIGNATURE=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')

curl -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Source: $SOURCE" \
  -H "X-Webhook-Signature: $SIGNATURE" \
  -H "X-Webhook-Timestamp: $TIMESTAMP" \
  -H "X-Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d "$BODY"
```

### Python Example

```python
import hashlib
import hmac
import time
import requests

webhook_url = "https://example.com/api/webhook"
secret = "your-webhook-secret"
source = "github"
body = '{"action":"push","repository":"test"}'

timestamp = str(int(time.time()))
signature = hmac.new(
    secret.encode(),
    body.encode(),
    hashlib.sha256
).hexdigest()

headers = {
    "Content-Type": "application/json",
    "X-Webhook-Source": source,
    "X-Webhook-Signature": signature,
    "X-Webhook-Timestamp": timestamp,
    "X-Idempotency-Key": f"github-push-{timestamp}",
}

response = requests.post(webhook_url, data=body, headers=headers)
print(response.status_code)  # 202 Accepted
```

### JavaScript Example

```javascript
import crypto from 'crypto';

async function sendWebhook(secret, source, body) {
  const webhookUrl = 'https://example.com/api/webhook';
  const timestamp = Math.floor(Date.now() / 1000).toString();
  
  const signature = crypto
    .createHmac('sha256', secret)
    .update(body)
    .digest('hex');

  const response = await fetch(webhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Webhook-Source': source,
      'X-Webhook-Signature': signature,
      'X-Webhook-Timestamp': timestamp,
      'X-Idempotency-Key': `webhook-${timestamp}`,
    },
    body,
  });

  return response.status; // 202 Accepted
}
```

## Responses

### 202 Accepted

Webhook received and queued for processing. Response body:

```json
{
  "status": "queued"
}
```

Even if duplicate (same idempotency key), returns 202 without error.

### 400 Bad Request

Missing required headers.

```
Missing required webhook headers
```

### 401 Unauthorized

Invalid signature or timestamp outside tolerance.

```
Invalid webhook signature
```

Or:

```
Webhook timestamp out of tolerance
```

## Processing Webhooks

### Automatic Processing

Received payloads are automatically queued for async processing via `ProcessWebhookPayload` job.

The job:
1. Runs in queue (not inline)
2. Marks payload as `processed` on success
3. Marks payload as `failed` and increments `retry_count` on error
4. Can be extended to dispatch domain-specific jobs

### Extending Processing

Edit `app/Jobs/ProcessWebhookPayload.php` to dispatch source-specific handlers:

```php
public function handle(): void
{
    try {
        match($this->payload->webhook_source) {
            'github' => GitHubWebhookJob::dispatch($this->payload),
            'stripe' => StripeWebhookJob::dispatch($this->payload),
            default => Log::warning("Unknown webhook source", 
                ['source' => $this->payload->webhook_source])
        };

        $this->payload->markProcessed();
    } catch (\Exception $e) {
        $this->payload->markFailed($e->getMessage());
        throw $e;
    }
}
```

Then create source-specific jobs:

```php
// app/Jobs/GitHubWebhookJob.php
class GitHubWebhookJob implements ShouldQueue
{
    public function __construct(private WebhookPayload $payload) {}

    public function handle(): void
    {
        $action = $this->payload->payload['action'];
        
        match($action) {
            'push' => $this->handlePush(),
            'pull_request' => $this->handlePullRequest(),
            default => Log::debug("Unhandled GitHub action", 
                ['action' => $action])
        };
    }

    private function handlePush(): void
    {
        // Custom GitHub push logic
    }
}
```

## Database Schema

### webhook_payloads Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| webhook_source | string | Source identifier (e.g., 'github') |
| idempotency_key | string | Unique key (prevents duplicates) |
| payload | json | Raw webhook payload |
| received_at | timestamp | When webhook arrived |
| processed_at | timestamp | When processing completed |
| status | string | `pending`, `processed`, or `failed` |
| error_message | text | Error details if failed |
| retry_count | integer | Number of retry attempts |
| created_at | timestamp | Created at |
| updated_at | timestamp | Updated at |

### Querying Payloads

```php
// Pending webhooks
WebhookPayload::where('status', 'pending')->get();

// Failed webhooks (for retry)
WebhookPayload::where('status', 'failed')->get();

// By source
WebhookPayload::where('webhook_source', 'github')->get();

// Recent
WebhookPayload::whereBetween('received_at', [
    now()->subHour(),
    now()
])->get();
```

## Security

### Signature Verification

- **Algorithm**: HMAC-SHA256
- **Comparison**: `hash_equals()` (timing-attack resistant)
- **Secret storage**: Environment variables (use secrets manager in production)

### Timestamp Validation

- **Tolerance**: 5 minutes (configurable in `config/webhooks.php`)
- **Prevents**: Replay attacks

### Idempotency

- **Key**: Unique per webhook source + webhook event
- **Storage**: Database (survives service restart)
- **Prevents**: Duplicate processing on network retries

## Testing

### Unit Tests

```bash
php artisan test tests/Feature/WebhookTest.php
```

Tests verify:
- ✓ Invalid signature returns 401
- ✓ Timestamp outside tolerance returns 401
- ✓ Duplicate payload (same idempotency key) processes once
- ✓ Valid webhook returns 202 and queues job
- ✓ Payload stored and processed correctly
- ✓ Missing headers returns 400

### Manual Testing

```bash
# Start queue worker
php artisan queue:work

# In another terminal, send webhook
curl -X POST http://localhost/api/webhook \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Source: github" \
  -H "X-Webhook-Signature: $(echo -n '{...}' | openssl dgst -sha256 -hmac 'secret' | sed 's/^.*= //')" \
  -H "X-Webhook-Timestamp: $(date +%s)" \
  -H "X-Idempotency-Key: test-123" \
  -d '{...}'

# Check stored payload
php artisan tinker
>>> WebhookPayload::latest()->first()
```

## Debugging

### View Webhook Payload

```php
$payload = WebhookPayload::where('idempotency_key', 'abc-123')->first();
dump($payload->payload);
dump($payload->status);
dump($payload->error_message);
```

### Check Job Queue

```bash
# Redis queue depth
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Monitor queue
php artisan horizon  # if using Horizon
```

### Replay Webhook

```php
$payload = WebhookPayload::where('status', 'failed')->first();
ProcessWebhookPayload::dispatch($payload);
```

## Configuration Reference

`config/webhooks.php`:

```php
'sources' => [
    'source-name' => [
        'secret' => env('WEBHOOK_SOURCE_SECRET', ''),
    ],
],

'signature_header' => 'X-Webhook-Signature',
'source_header' => 'X-Webhook-Source',
'timestamp_header' => 'X-Webhook-Timestamp',
'idempotency_header' => 'X-Idempotency-Key',

'timestamp_tolerance' => 5 * 60, // seconds
```

## Best Practices

1. **Use strong secrets**: Generate with `openssl rand -hex 32`
2. **Rotate secrets periodically**: Implement secret versioning
3. **Monitor failed webhooks**: Set up alerts for `status = 'failed'`
4. **Log all webhooks**: Use `received_at` and `processed_at` for tracing
5. **Test signature verification**: Verify with `hash_equals()`, not `==`
6. **Queue processing**: Never process inline; always use jobs
7. **Idempotency keys**: Ensure client sends unique keys per webhook
8. **Dead letter queue**: Archive old failed payloads periodically

## References

- [GitHub Webhooks](https://docs.github.com/en/webhooks)
- [Stripe Webhooks](https://stripe.com/docs/webhooks)
- [OWASP: Webhook Security](https://cheatsheetseries.owasp.org/cheatsheets/Webhook_Security_Cheat_Sheet.html)
