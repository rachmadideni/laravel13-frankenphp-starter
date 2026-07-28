<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookPayload;
use App\Models\WebhookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $source = 'github';

    protected string $secret = 'webhook-secret-for-testing';

    protected function setUp(): void
    {
        parent::setUp();

        // Set webhook secret in config
        config()->set('webhooks.sources.github.secret', $this->secret);
    }

    #[Test]
    public function invalid_signature_returns_401(): void
    {
        $body = json_encode(['action' => 'push', 'repository' => 'test']);
        $wrongSignature = hash_hmac('sha256', 'wrong-body', $this->secret);
        $timestamp = (string) time();

        $response = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            'X-Webhook-Source' => $this->source,
            'X-Webhook-Signature' => $wrongSignature,
            'X-Webhook-Timestamp' => $timestamp,
            'X-Idempotency-Key' => 'test-key-1',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseEmpty('webhook_payloads');
    }

    #[Test]
    public function timestamp_outside_tolerance_returns_401(): void
    {
        $body = json_encode(['action' => 'push']);
        $signature = hash_hmac('sha256', $body, $this->secret);
        // 10 minutes in the past (tolerance is 5 minutes)
        $oldTimestamp = (string) (time() - 600);

        $response = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            'X-Webhook-Source' => $this->source,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => $oldTimestamp,
            'X-Idempotency-Key' => 'test-key-2',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseEmpty('webhook_payloads');
    }

    #[Test]
    public function duplicate_payload_with_same_idempotency_key_returns_202_only_once(): void
    {
        Queue::fake();

        $body = json_encode(['action' => 'push', 'id' => 123]);
        $signature = hash_hmac('sha256', $body, $this->secret);
        $timestamp = (string) time();
        $idempotencyKey = 'test-key-duplicate';

        // First request
        $response1 = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            'X-Webhook-Source' => $this->source,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => $timestamp,
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $response1->assertStatus(202);
        $this->assertDatabaseCount('webhook_payloads', 1);

        // Second request with same idempotency key
        $response2 = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            'X-Webhook-Source' => $this->source,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => (string) time(), // Fresh timestamp is ok
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $response2->assertStatus(202);
        // Still only 1 in database, not 2
        $this->assertDatabaseCount('webhook_payloads', 1);

        // Exactly 1 job dispatched (from first request only)
        Queue::assertDispatched(ProcessWebhookPayload::class, 1);
    }

    #[Test]
    public function valid_webhook_returns_202_and_queues_job(): void
    {
        Queue::fake();

        $body = json_encode(['action' => 'push', 'repository' => 'test-repo']);
        $signature = hash_hmac('sha256', $body, $this->secret);
        $timestamp = (string) time();

        $response = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            'X-Webhook-Source' => $this->source,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => $timestamp,
            'X-Idempotency-Key' => 'test-key-3',
        ]);

        $response->assertStatus(202);
        $this->assertJson($response->getContent());

        // Payload stored in database
        $this->assertDatabaseHas('webhook_payloads', [
            'webhook_source' => $this->source,
            'idempotency_key' => 'test-key-3',
            'status' => 'pending',
        ]);

        // Job queued for async processing
        Queue::assertDispatched(ProcessWebhookPayload::class, 1);

        Queue::assertDispatched(ProcessWebhookPayload::class, function ($job) {
            return $job->payload->webhook_source === $this->source;
        });
    }

    #[Test]
    public function webhook_payload_processes_correctly(): void
    {
        $body = json_encode(['action' => 'push', 'number' => 42]);
        $signature = hash_hmac('sha256', $body, $this->secret);
        $timestamp = (string) time();

        $response = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            'X-Webhook-Source' => $this->source,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => $timestamp,
            'X-Idempotency-Key' => 'test-key-4',
        ]);

        $response->assertStatus(202);

        $payload = WebhookPayload::where('idempotency_key', 'test-key-4')->firstOrFail();
        $this->assertEquals('pending', $payload->status);
        $this->assertEquals(['action' => 'push', 'number' => 42], $payload->payload);

        // Process the job
        $job = new \App\Jobs\ProcessWebhookPayload($payload);
        $job->handle();

        // Verify payload marked as processed
        $payload->refresh();
        $this->assertEquals('processed', $payload->status);
        $this->assertNotNull($payload->processed_at);
    }

    #[Test]
    public function missing_headers_returns_400(): void
    {
        $body = json_encode(['action' => 'push']);

        $response = $this->post('/webhook', $body, [
            'Content-Type' => 'application/json',
            // Missing all required headers
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseEmpty('webhook_payloads');
    }
}
