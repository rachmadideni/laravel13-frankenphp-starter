# Removing Demo Files

This boilerplate includes a vertical demo (`@demo` label) that demonstrates the full stack: queue job, broadcast event, and Pinia store update.

When using this boilerplate for a real project, remove demo files by following this checklist.

## Demo Files

All files below are marked with `@demo` comment and can be safely deleted:

### Backend

**Models & Resources:**
- `app/Models/Item.php` — Example model with database integration
- `app/Http/Resources/ItemResource.php` — API response shape
- `app/Policies/ItemPolicy.php` — Authorization policy example

**Jobs & Events:**
- `app/Jobs/ItemDemoJob.php` — Queue job that broadcasts on completion
- `app/Events/ItemDemoCompleted.php` — Broadcast event

**Controllers:**
- `app/Http/Controllers/ItemDemoController.php` — Endpoints for /api/items and /api/items/demo

**Database:**
- `database/migrations/2026_07_28_000000_create_items_table.php` — Items table
- `database/factories/ItemFactory.php` — Test data factory

**Tests:**
- `tests/Feature/ItemDemoFlowTest.php` — Tests for job → broadcast → store flow
- `tests/Feature/ItemDemoEndpointTest.php` — Tests for demo endpoint (GET /api/items, POST /api/items/demo)

### Frontend

**Store:**
- `frontend/src/stores/items.ts` — Pinia store with Echo listener
- `frontend/src/stores/items.spec.ts` — Store tests

**Components:**
- `frontend/src/components/ItemsDemo.vue` — Component displaying items list with demo button

**Pages:**
- `frontend/src/pages/Demo.vue` — Page showcasing full stack integration

**Router:**
- Edit `frontend/src/router/index.ts`: Remove the `import DemoView from '../pages/Demo.vue'` line and the `/demo` route

### Routes & Controllers

**routes/api.php:**
- Remove lines for demo routes: `GET /api/items`, `POST /api/items/demo`
- Remove import: `use App\Http\Controllers\ItemDemoController;`

**routes/channels.php:**
- Check for `items.*` channels — may have been added for demo — remove them

### Seeding

**RolePermissionSeeder:**
- Check `database/seeders/RolePermissionSeeder.php` for demo permissions like `items.view`, `items.create`
- Remove permission creation lines related to items

## Removal Steps

### 1. Delete Files

```bash
# Backend
rm app/Models/Item.php
rm app/Http/Resources/ItemResource.php
rm app/Policies/ItemPolicy.php
rm app/Http/Controllers/ItemDemoController.php
rm app/Jobs/ItemDemoJob.php
rm app/Events/ItemDemoCompleted.php

# Database
rm database/migrations/2026_07_28_000000_create_items_table.php
rm database/factories/ItemFactory.php

# Tests
rm tests/Feature/ItemDemoFlowTest.php
rm tests/Feature/ItemDemoEndpointTest.php

# Frontend Components & Pages
rm frontend/src/components/ItemsDemo.vue
rm frontend/src/pages/Demo.vue
rm frontend/src/stores/items.ts
rm frontend/src/stores/items.spec.ts
```

### 2. Clean Routes

**routes/api.php:**
Remove demo routes and imports:
```php
// Remove this import:
use App\Http\Controllers\ItemDemoController;

// Remove these routes:
Route::get('/items', [ItemDemoController::class, 'index']);
Route::post('/items/demo', [ItemDemoController::class, 'triggerDemo'])->middleware('auth:sanctum');
```

**routes/channels.php:**
Remove demo channels (if any were added):
```php
// Remove these if present:
Broadcast::channel('items.demo', function ($user) {
    return $user->can('items.view');
});
```

### 3. Update Seeder

**database/seeders/RolePermissionSeeder.php:**
Remove demo permissions:
```php
// Remove lines like:
Permission::create(['name' => 'items.view']);
Permission::create(['name' => 'items.create']);
Permission::create(['name' => 'items.delete']);
```

### 4. Update Frontend Store

**frontend/src/main.ts:**
Remove import of demo store:
```typescript
// Remove if present:
import { useItemsStore } from './stores/items'
```

### 5. Update Tests

**tests/Feature/:** Delete any test files for Item resource if you added them

**frontend/vitest.config.ts:** Update if it references removed stores

### 6. Verify Removal

```bash
# No references to Item should remain (except in comments/docs)
grep -r "ItemDemo\|ItemResource\|ItemPolicy\|useItemsStore" \
  app/ routes/ database/ tests/ frontend/src/ --exclude-dir=node_modules

# Should return nothing or only documentation mentions
```

### 7. Run Quality Gates

```bash
# Backend
composer verify

# Frontend
npm --prefix frontend run verify
```

### 8. Test Build & Run

```bash
# Dev mode
podman compose up -d
podman compose exec app php artisan migrate --seed
curl http://localhost/up    # should still respond

# Production mode
podman build -f container/Containerfile.prod -t myapp:test .
podman run --rm -p 8000:80 myapp:test php artisan --version
```

## Full Checklist

- [ ] Delete Item model, resource, policy
- [ ] Delete ItemDemoJob, ItemDemoCompleted event
- [ ] Delete items table migration
- [ ] Delete ItemFactory
- [ ] Delete ItemDemoFlowTest
- [ ] Delete items Pinia store and tests
- [ ] Remove /items routes from routes/api.php
- [ ] Remove items channels from routes/channels.php
- [ ] Remove items permissions from seeder
- [ ] Remove store imports from frontend/main.ts
- [ ] composer verify passes
- [ ] npm --prefix frontend run verify passes
- [ ] podman compose up -d works
- [ ] curl http://localhost/up works
- [ ] Production build succeeds

## What Remains After Removal

After removing all demo files, you have a clean boilerplate:

- User/Role/Permission RBAC fully wired
- Sanctum authentication configured
- Reverb WebSocket server ready for real channels
- Horizon queue worker ready for real jobs
- S3/MinIO presigned upload ready for real uploads
- Database migrations framework ready
- Full test suite framework (Pest)
- Production deployment scripts ready
- All infrastructure (Podman, Postgres, Redis, MinIO)

This is the **non-demo skeleton** ready for your domain models.

## Example: Building a Task App

After removing Item demo, to build a task management app:

1. Create `app/Models/Task.php` with `HasMany` relationship to User
2. Create `app/Http/Resources/TaskResource.php`
3. Create `app/Http/Controllers/TaskController.php` with CRUD
4. Add routes in `routes/api.php`: `Route::apiResource('tasks', TaskController::class)`
5. Create migration `create_tasks_table.php`
6. Create factory `TaskFactory.php` in database/factories
7. Add permissions in seeder: `tasks.view`, `tasks.create`, etc.
8. Create tests in `tests/Feature/TaskTest.php`
9. Run: `composer verify` → should all pass

You now have a fresh skeleton ready for your domain.
