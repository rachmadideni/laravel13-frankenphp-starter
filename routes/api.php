<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PresignedUploadController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ItemDemoController;
use Illuminate\Support\Facades\Route;

// Webhook endpoint (no auth required, signature-based verification)
Route::post('/webhook', [WebhookController::class, 'receive']);

// @demo: Item demo endpoints (full stack: request → job → broadcast → store)
Route::get('/items', [ItemDemoController::class, 'index']);
Route::post('/items/demo', [ItemDemoController::class, 'triggerDemo'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/uploads/presigned', [PresignedUploadController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'permission:item.view'])->group(function () {
    Route::get('/items', fn () => response()->json(['data' => []]));
});
