<!-- @demo: End-to-end demo component showing request → job → broadcast → store flow -->
<template>
  <div class="items-demo">
    <v-card>
      <v-card-title>
        <h2>Items Demo</h2>
        <p class="text-caption">Full stack: Click button → Job queues → Event broadcasts → Store updates (no reload)</p>
      </v-card-title>

      <v-card-text>
        <!-- Demo trigger button -->
        <v-btn
          color="primary"
          :loading="triggeringDemo"
          @click="triggerDemo"
          class="mb-4"
        >
          Trigger Demo Job
        </v-btn>

        <p v-if="error" class="text-error">{{ error }}</p>

        <!-- Items list from store -->
        <v-list v-if="items.length">
          <v-list-item
            v-for="item in items"
            :key="item.id"
            :title="item.name"
            :subtitle="item.description"
          >
            <template #append>
              <v-chip
                :color="item.status === 'completed' ? 'success' : 'warning'"
                :text-color="'white'"
              >
                {{ item.status }}
              </v-chip>
            </template>
          </v-list-item>
        </v-list>
        <p v-else class="text-muted">No items yet. Click the button above to create one.</p>
      </v-card-text>

      <v-card-actions>
        <v-spacer></v-spacer>
        <p class="text-caption text-muted">
          Items in store: {{ items.length }} | Last update: {{ lastUpdate || 'never' }}
        </p>
      </v-card-actions>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useItemsStore } from '@/stores/items'
import { useAuthStore } from '@/stores/auth'

const itemsStore = useItemsStore()
const authStore = useAuthStore()
const triggeringDemo = ref(false)
const error = ref('')
const lastUpdate = ref('')

const items = computed(() => itemsStore.items)

onMounted(() => {
  // Initialize store and connect to broadcast listener
  itemsStore.initialize()
})

const triggerDemo = async () => {
  if (!authStore.user) {
    error.value = 'Please log in to trigger demo'
    return
  }

  triggeringDemo.value = true
  error.value = ''

  try {
    const response = await fetch('/api/items/demo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
    })

    if (!response.ok) {
      throw new Error(`Failed: ${response.statusText}`)
    }

    // Update last update time
    lastUpdate.value = new Date().toLocaleTimeString()

    // Store will be updated by Echo listener receiving broadcast
    // No manual refresh needed!
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unknown error'
  } finally {
    triggeringDemo.value = false
  }
}
</script>

<style scoped>
.items-demo {
  max-width: 600px;
}

.v-card-title p {
  margin: 0;
  font-size: 0.875rem;
}
</style>
