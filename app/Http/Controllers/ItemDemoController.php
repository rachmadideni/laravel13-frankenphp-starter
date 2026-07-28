<?php

namespace App\Http\Controllers;

use App\Jobs\ItemDemoJob;
use App\Models\Item;
use App\Http\Resources\ItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ItemDemoController extends Controller
{
    // @demo
    /**
     * List items
     */
    public function index(): JsonResponse
    {
        $items = Item::orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => ItemResource::collection($items),
        ]);
    }

    // @demo
    /**
     * Trigger demo job that broadcasts update to Pinia store
     *
     * Demonstrates full stack: request → job → broadcast → store update
     */
    public function triggerDemo(): Response
    {
        // Create a demo item if none exist
        $item = Item::firstOrCreate(
            ['name' => 'Demo Item'],
            ['description' => 'Example item from demo flow', 'status' => 'pending']
        );

        // Dispatch job that will:
        // 1. Update item status to 'completed'
        // 2. Broadcast ItemDemoCompleted event
        // 3. Echo listener in items.ts receives broadcast
        // 4. Pinia store updates without page reload
        ItemDemoJob::dispatch($item);

        return response(null, Response::HTTP_ACCEPTED);
    }
}
