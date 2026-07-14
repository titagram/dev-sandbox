# Work Queue Reclaim Race Test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the same-device reclaim race test assert the real concurrency contract: a terminal completion wins, no replacement lease or duplicate claim event is created, and the claim request returns conflict.

**Status:** Complete and verified. The regression-only correction was committed without production-code changes.

**Architecture:** The controller already uses a conditional update over `claimed|running`. The failing test injects a `completed` transition before that update, so the conditional update correctly loses. Fix the stale rollback expectation and avoid production-code changes unless the corrected regression exposes one.

**Tech Stack:** Laravel 13, Pest, SQLite in-memory test database, conditional SQL updates.

## Global Constraints

- Never change `completed` back to `claimed` after a lost reclaim race.
- Keep the HTTP 409 contract.
- Create no replacement lease or duplicate `claimed` event when the conditional update affects zero rows.
- Run the test in isolation and the complete file.

---

### Task 1: Correct and strengthen the race contract

**Files:**
- Modify: `backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php`

**Interfaces:**
- Consumes: `AgentWorkItemController::claim(Request $request, string $workItem): JsonResponse`.
- Produces: regression coverage for terminal-state precedence and lease/event idempotency.

- [x] **Step 1: Reproduce RED**

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php \
  --filter="does not create a replacement lease"
```

Expected: FAIL at `toBe('claimed')`, actual `completed`.

- [x] **Step 2: Rename the test around the contract**

```php
it('preserves a concurrent terminal transition when same-device reclaim loses the conditional update', function () {
```

- [x] **Step 3: Assert the terminal winner and unchanged side effects**

```php
expect($completedDuringClaim)->toBeTrue()
    ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('completed')
    ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('completed_at'))->not->toBeNull()
    ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->count())->toBe($initialLeaseCount)
    ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->whereNull('released_at')->count())->toBe(1)
    ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'claimed')->count())->toBe($initialClaimedEventCount);
```

The active lease remains because the synthetic listener changes only the work-item row; it proves the losing reclaim did not replace the lease.

- [x] **Step 4: Run isolated GREEN**

Run the Step 1 command with the new test name filter. Expected: 1 passing test and no production-code diff.

- [x] **Step 5: Run the whole contract file**

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php --display-warnings
```

Expected: zero failures.

- [x] **Step 6: Run sibling work-item contracts**

```bash
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php \
  tests/Feature/Plugin/AgentWorkItemContractTest.php \
  tests/Feature/Dashboard/AgentWorkDashboardApiTest.php --display-warnings
```

Expected: zero failures attributable to this change.

The process-only test key is intentionally non-secret and compensates for isolated worktrees that do not contain `backend/.env`; it is not written to a file.
