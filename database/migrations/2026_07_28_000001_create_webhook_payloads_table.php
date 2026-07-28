<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_payloads', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_source'); // e.g., 'github', 'stripe', 'external-api'
            $table->string('idempotency_key')->unique(); // Prevent duplicate processing
            $table->json('payload'); // Raw webhook payload
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status')->default('pending'); // pending, processed, failed
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();

            $table->index('webhook_source');
            $table->index('status');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_payloads');
    }
};
