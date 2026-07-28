<!-- @demo: Page showcasing full stack integration -->
<template>
  <div class="demo-page">
    <v-container>
      <v-row>
        <v-col>
          <h1>Full Stack Demo</h1>
          <p class="subtitle">
            This page demonstrates the complete flow: request → queue job → broadcast event → Pinia store update.
          </p>
        </v-col>
      </v-row>

      <v-row>
        <v-col>
          <v-card class="mb-4">
            <v-card-title>Architecture</v-card-title>
            <v-card-text>
              <ol>
                <li><strong>Frontend:</strong> Click button to send request to /api/items/demo</li>
                <li><strong>Backend:</strong> Controller queues ItemDemoJob to Redis</li>
                <li><strong>Queue Worker (Horizon):</strong> Processes job, updates DB, broadcasts event</li>
                <li><strong>Laravel Reverb:</strong> Delivers event via WebSocket to browser</li>
                <li><strong>Echo Listener:</strong> Received in items.ts store initialization</li>
                <li><strong>Pinia Store:</strong> Updates state, Vue re-renders without page reload</li>
              </ol>
              <p class="mt-4 text-caption">
                All steps happen asynchronously. UI responds with 202 Accepted immediately.
              </p>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <v-row>
        <v-col>
          <ItemsDemo />
        </v-col>
      </v-row>

      <v-row>
        <v-col>
          <v-card>
            <v-card-title>How It Works</v-card-title>
            <v-card-text>
              <v-expansion-panels>
                <v-expansion-panel title="Frontend Request">
                  <template #text>
                    <pre><code>// frontend/src/components/ItemsDemo.vue
const triggerDemo = async () => {
  await fetch('/api/items/demo', { method: 'POST' })
  // Returns 202 Accepted immediately
  // Store updates via broadcast, no manual refresh needed
}</code></pre>
                  </template>
                </v-expansion-panel>

                <v-expansion-panel title="Backend Job">
                  <template #text>
                    <pre><code>// app/Jobs/ItemDemoJob.php
class ItemDemoJob implements ShouldQueue {
  public function handle(): void {
    $item->update(['status' => 'completed'])
    ItemDemoCompleted::dispatch($item)
    // Event broadcasts immediately to WebSocket
  }
}</code></pre>
                  </template>
                </v-expansion-panel>

                <v-expansion-panel title="Store Updates">
                  <template #text>
                    <pre><code>// frontend/src/stores/items.ts
export const useItemsStore = defineStore('items', () => {
  // Listen for broadcast on 'items.demo' channel
  Echo.channel('items.demo').listen('.ItemDemoCompleted', (event) => {
    updateItem(event.data)  // Updates Pinia state
  })
})</code></pre>
                  </template>
                </v-expansion-panel>

                <v-expansion-panel title="Remove Demo">
                  <template #text>
                    <pre><code>// Follow docs/REMOVE-DEMO.md to delete all @demo files:
rm app/Models/Item.php
rm app/Jobs/ItemDemoJob.php
rm app/Events/ItemDemoCompleted.php
rm app/Http/Controllers/ItemDemoController.php
rm app/Http/Resources/ItemResource.php
rm database/migrations/2026_07_28_000000_create_items_table.php
rm frontend/src/components/ItemsDemo.vue
rm frontend/src/pages/Demo.vue
# ... and more (see docs/REMOVE-DEMO.md)</code></pre>
                  </template>
                </v-expansion-panel>
              </v-expansion-panels>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </v-container>
  </div>
</template>

<script setup lang="ts">
import ItemsDemo from '@/components/ItemsDemo.vue'
</script>

<style scoped>
.demo-page {
  padding: 2rem 0;
}

.subtitle {
  font-size: 1.125rem;
  color: var(--v-medium-emphasis);
  margin-top: 0.5rem;
}

ol {
  padding-left: 1.5rem;
}

li {
  margin-bottom: 0.5rem;
}

pre {
  background: var(--v-surface-variant);
  padding: 1rem;
  border-radius: 4px;
  overflow-x: auto;
  font-size: 0.875rem;
}

code {
  font-family: 'Courier New', monospace;
}
</style>
