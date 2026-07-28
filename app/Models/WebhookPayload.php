<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookPayload extends Model
{
    protected $fillable = [
        'webhook_source',
        'idempotency_key',
        'payload',
        'received_at',
        'processed_at',
        'status',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'payload' => 'json',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function markProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'retry_count' => $this->retry_count + 1,
        ]);
    }
}
