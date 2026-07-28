<?php

namespace App\Jobs;

use App\Models\WebhookPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookPayload implements ShouldQueue
{
    use Queueable;

    public function __construct(private WebhookPayload $payload)
    {
    }

    /**
     * Process webhook payload
     *
     * This is a generic handler that stores the payload and marks it processed.
     * Applications should dispatch domain-specific jobs based on webhook_source
     * and payload content.
     */
    public function handle(): void
    {
        try {
            // In production, dispatch source-specific processing based on webhook_source
            // Example:
            // match($this->payload->webhook_source) {
            //     'github' => GitHubWebhookJob::dispatch($this->payload),
            //     'stripe' => StripeWebhookJob::dispatch($this->payload),
            //     default => Log::warning("Unknown webhook source", ['source' => $this->payload->webhook_source])
            // };

            // For now, just mark as processed
            $this->payload->markProcessed();
        } catch (\Exception $e) {
            $this->payload->markFailed($e->getMessage());
            throw $e;
        }
    }
}
