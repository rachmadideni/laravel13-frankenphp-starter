<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookPayload;
use App\Models\WebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    /**
     * Receive and store webhook payload, queue async processing
     *
     * Expects headers:
     * - X-Webhook-Source: webhook source identifier (e.g., 'github', 'stripe')
     * - X-Webhook-Signature: HMAC-SHA256 signature of raw body
     * - X-Webhook-Timestamp: Unix timestamp (tolerance: 5 minutes)
     * - X-Idempotency-Key: Unique key to prevent duplicate processing
     *
     * Returns 202 Accepted immediately, processes async in queue.
     */
    public function receive(Request $request): Response
    {
        // Get headers
        $source = $request->header('X-Webhook-Source');
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $idempotencyKey = $request->header('X-Idempotency-Key');

        // Validate required headers
        if (!$source || !$signature || !$timestamp || !$idempotencyKey) {
            return response('Missing required webhook headers', Response::HTTP_BAD_REQUEST);
        }

        // Get raw body for signature verification
        $rawBody = $request->getContent();

        // Verify timestamp (tolerance: 5 minutes)
        if (!$this->isTimestampValid($timestamp)) {
            return response('Webhook timestamp out of tolerance', Response::HTTP_UNAUTHORIZED);
        }

        // Verify HMAC signature
        if (!$this->verifySignature($rawBody, $signature, $source)) {
            return response('Invalid webhook signature', Response::HTTP_UNAUTHORIZED);
        }

        // Check for duplicate (idempotency)
        $existing = WebhookPayload::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            // Already processed or queued, return same response
            return response(json_encode(['status' => 'queued']), Response::HTTP_ACCEPTED);
        }

        // Store raw payload
        $payload = WebhookPayload::create([
            'webhook_source' => $source,
            'idempotency_key' => $idempotencyKey,
            'payload' => json_decode($rawBody, true) ?? [],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        // Queue async processing
        ProcessWebhookPayload::dispatch($payload);

        return response(json_encode(['status' => 'queued']), Response::HTTP_ACCEPTED);
    }

    /**
     * Verify HMAC-SHA256 signature
     * Signature = HMAC-SHA256(raw_body, secret_key_for_source)
     *
     * Uses hash_equals to prevent timing attacks
     */
    private function verifySignature(string $body, string $signature, string $source): bool
    {
        // Get secret key for this webhook source
        // In production, store secrets securely (e.g., 1Password, AWS Secrets Manager)
        $secret = config("webhooks.sources.{$source}.secret");

        if (!$secret) {
            return false;
        }

        // Calculate expected signature
        $expectedSignature = hash_hmac('sha256', $body, $secret);

        // Use hash_equals to prevent timing-based attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify timestamp is within acceptable tolerance (5 minutes)
     */
    private function isTimestampValid(string $timestamp): bool
    {
        $tolerance = 5 * 60; // 5 minutes in seconds

        $requestTime = (int) $timestamp;
        $currentTime = time();

        return abs($currentTime - $requestTime) <= $tolerance;
    }
}
