# Browser Testing

## Current Browser Surfaces

- `verified_from_code`: Current DevBoard browser pages are Laravel web/Inertia routes.
- `verified_from_code`: The generated emergent.sh frontend is a separate React 19 CRA/CRACO app with mock API data and a typed HTTP adapter.
- `verified_from_code`: The generated frontend build passed only when `TSC_COMPILE_ON_ERROR=true` was supplied, matching its README_LLM caveat about untyped shadcn `.jsx` components.
- `verified_from_code`: The generated frontend has no test files in `frontend/src` at intake time.

## Playwright Position

- `needs_verification`: Playwright is not currently declared in either active frontend dependency file inspected during Task 1.
- `verified_from_code`: `backend/package.json` has no `test`, `e2e`, or Playwright script at Task 8.
- `verified_from_code`: `backend/tests/e2e/README.md` now documents the future browser smoke scope without adding a browser dependency.
- `inferred`: Playwright should be installed only in the branch that owns the active browser shell.
- `inferred`: Browser smoke should start with login, primary navigation, Quality Center read-only screens, and one permission-denied path.
- `inferred`: Browser tests should not trigger destructive scans, active security probes, payment flows, email sends, or mutating route scans by default.
- `inferred`: If the emergent.sh frontend becomes the active shell, Playwright should be added and run in that frontend workspace first, not silently in the Laravel backend.

## Frontend Integration Checks

- `verified_from_code`: The generated frontend `httpApi.ts` uses `credentials: "include"` and `/api/dashboard` paths.
- `inferred`: Before switching from mock to HTTP, DevBoard should expose `/api/dashboard/me` and enough read-only endpoints to keep navigation stable.
- `inferred`: A hybrid adapter can keep unavailable endpoints mocked while the backend API lands incrementally.
- `inferred`: Browser network verification should confirm that the UI does not call `/api/plugin/v1`.
- `verified_from_code`: Quality Center read-only data is available under `/api/dashboard/quality/*`; run endpoints should stay disabled in UI until backend permissions and approval flags are implemented.

## Visual Quality Checks

- `inferred`: Dashboard visual QA should cover desktop and mobile navigation, dense tables, status badges, modals, disabled destructive controls, and empty/error/loading states.
- `inferred`: Quality Center screens should make implemented, configured-disabled, missing-setup, warning, blocking, and approval-required states visually distinct.
