# Work Queue Reclaim Race Fix Report

## Status

`DONE_WITH_CONCERNS`

The same-device reclaim race regression now asserts the actual concurrency contract. The terminal `completed` transition wins, the claim endpoint returns HTTP 409, and the losing reclaim creates neither a replacement lease nor a duplicate `claimed` event. Production code was not changed.

## Scope and evidence

- Binding plan: `docs/superpowers/plans/2026-07-14-work-queue-reclaim-race-test.md`.
- Changed regression: `backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php`.
- Controller inspected read-only: `backend/app/Http/Controllers/Plugin/AgentWorkItemController.php`.
- Originating test commit inspected read-only: `ee71fb35` (`fix plugin work queue lease and scoping guards`).

The controller reclaims an item only through a conditional update constrained to the same device and status `claimed|running`. The synthetic query listener moves the row to `completed` before that update. The update therefore affects zero rows and the controller returns `work_item_claim_conflict` (HTTP 409) before lease replacement or event creation. Reverting the terminal row to `claimed` would violate that contract.

## Change

- Renamed the test to `preserves a concurrent terminal transition when same-device reclaim loses the conditional update`.
- Changed the stale final-status assertion from `claimed` to `completed`.
- Added an assertion that `completed_at` is not null.
- Retained the lease-count, active-lease-count, and `claimed` event-count invariants.
- Made no production-code changes.

## TDD and verification

### RED

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php \
  --filter="does not create a replacement lease"
```

Result: expected failure at line 442; expected `claimed`, actual `completed` (1 failed, 4 assertions).

### Isolated GREEN

The renamed filter completed with zero failures: 1 warning, 8 assertions. The warning is the pre-existing missing `backend/.env` file read.

### Full contract file

The full `PluginSharedMemoryAndWorkQueueTest.php` run completed with zero failures: 19 warnings, 126 assertions.

### Sibling contracts

The exact three-file command from the plan initially produced 26 failures in `AgentWorkDashboardApiTest.php`, all caused by `MissingAppKeyException` because this isolated worktree has no `backend/.env`; the plugin race and contract tests did not fail.

The same three-file command was repeated with an ephemeral process-only test key:

```bash
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php \
  tests/Feature/Plugin/AgentWorkItemContractTest.php \
  tests/Feature/Dashboard/AgentWorkDashboardApiTest.php --display-warnings
```

Result: zero failures, 46 warnings, 326 assertions. All warnings are the same missing `backend/.env` file read. No `.env` file was created or modified.

## Concerns

- The plan's exact sibling command is environment-dependent in this worktree: dashboard tests need `APP_KEY` supplied externally.
- PHPUnit emits missing-`.env` warnings across these files even when all assertions pass.
- Hades backend status for this checkout is degraded/unmapped with an invalid agent token; this did not affect direct SSH implementation or local test execution.

## Commit scope

This report, the focused regression test, and the current project logbook section are the only files in the dedicated commit. The two implementation plans remain untracked inputs and were intentionally not staged.
