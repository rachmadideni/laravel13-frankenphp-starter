<?php

return [
    'sources' => [
        'github' => [
            'secret' => env('WEBHOOK_GITHUB_SECRET', ''),
        ],
        'stripe' => [
            'secret' => env('WEBHOOK_STRIPE_SECRET', ''),
        ],
        'external-api' => [
            'secret' => env('WEBHOOK_EXTERNAL_API_SECRET', ''),
        ],
    ],

    // Signature verification
    'signature_header' => 'X-Webhook-Signature',
    'source_header' => 'X-Webhook-Source',
    'timestamp_header' => 'X-Webhook-Timestamp',
    'idempotency_header' => 'X-Idempotency-Key',

    // Timestamp tolerance in seconds
    'timestamp_tolerance' => 5 * 60, // 5 minutes
];
