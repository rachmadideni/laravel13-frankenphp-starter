<?php

namespace Tests\Feature;

use App\Jobs\ItemDemoJob;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItemDemoEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function get_items_returns_item_list(): void
    {
        // Create test items
        Item::factory()->create(['name' => 'Item 1']);
        Item::factory()->create(['name' => 'Item 2']);

        $response = $this->getJson('/api/items');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'description', 'status', 'created_at', 'updated_at'],
            ],
        ]);
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function trigger_demo_without_auth_returns_401(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/items/demo');

        $response->assertStatus(401);
        Queue::assertNotDispatched(ItemDemoJob::class);
    }

    #[Test]
    public function trigger_demo_with_auth_returns_202_and_queues_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items/demo');

        $response->assertStatus(202);
        Queue::assertDispatched(ItemDemoJob::class);

        // Verify item was created with demo data
        $this->assertDatabaseHas('items', [
            'name' => 'Demo Item',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function trigger_demo_multiple_times_uses_same_item(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        // First trigger
        $this->actingAs($user)->postJson('/api/items/demo');
        $this->assertDatabaseCount('items', 1);

        // Second trigger should reuse same item
        $this->actingAs($user)->postJson('/api/items/demo');
        $this->assertDatabaseCount('items', 1);

        // Both jobs dispatched
        Queue::assertDispatched(ItemDemoJob::class, 2);
    }

    #[Test]
    public function full_demo_flow_end_to_end(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        // 1. Trigger demo (returns 202 immediately)
        $response = $this->actingAs($user)->postJson('/api/items/demo');
        $response->assertStatus(202);

        // 2. Verify job was queued
        Queue::assertDispatched(ItemDemoJob::class);

        // 3. Get items list (should show pending status)
        $listResponse = $this->getJson('/api/items');
        $listResponse->assertStatus(200);
        $items = $listResponse->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals('pending', $items[0]['status']);

        // 4. Process the job (what Horizon would do)
        $job = new \App\Jobs\ItemDemoJob($items[0] ? Item::find($items[0]['id']) : Item::first());
        $job->handle();

        // 5. Verify item status changed (broadcast would update store)
        $updatedItem = Item::find($items[0]['id'] ?? Item::first()->id);
        $this->assertEquals('completed', $updatedItem->status);
    }
}
