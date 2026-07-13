# Task 9 CAS race fix report

## Problem reproduced

The queue lifecycle allowed an exhausted delivery's delayed `failed()` hook to
overwrite a newer delivery that had already claimed the same projection. The
same lifecycle also performed a fallible read after the queued-to-projecting
CAS, and `markReady()` published candidates regardless of their current state.

The regression tests were added first and failed for all three behaviours.

## Implementation

- `claimForWorker()` now returns the conditional update result directly. The
  job loads immutable projection identity before claiming, removing the
  post-CAS read ambiguity.
- The final-failure hook uses `markFailedIfQueued()`. It can transition only
  `queued -> failed`, so it is a no-op after a newer delivery reaches
  `projecting`, `ready`, `stale`, or another terminal state.
- `markReady()` locks the candidate and returns `false` unless its locked state
  is `projecting`. Rejected transitions do not stale another ready projection
  and do not update counts, timestamps, or status.
- Synchronous Genesis projection callers now enter `projecting` before their
  database lifecycle is published as ready.
- Existing bounded failure-code mapping is retained.

## Verification

- Race-focused GREEN: 6 tests, 45 assertions, zero failures.
- Task 5/6/9 regression suite GREEN: 79 tests, 441 assertions, zero failures.
- Laravel Pint: seven scoped files passed; one spacing issue was fixed.
- `git diff --check`: passed.
- No migration was added. No live PostgreSQL or Neo4j operation was run.

The test runner still reports the pre-existing Pest temporary-results warning
caused by the container's missing `/workspace/backend/.env`; it does not affect
the assertions or exit status. The generated vendor temp file is intentionally
excluded from the commit.
