# Browser And E2E Readiness

This directory is reserved for browser smoke tests once the active DevBoard browser shell is selected.

## Current Position

- `verified_from_code`: the current backend frontend is Laravel Inertia/React under `backend/resources/js`.
- `verified_from_code`: the generated emergent.sh frontend was verified separately in `/home/ubuntu/emergent_devboard_frontend/frontend`.
- `verified_from_code`: `@playwright/test` is not declared in `backend/package.json`.
- `inferred`: Playwright should be installed only in the branch that owns the active browser shell.

## Safety Rules

- Browser smoke tests may read dashboard pages and quality reports.
- Browser smoke tests must not call `/api/plugin/v1`; that namespace is reserved for the local CLI/MCP plugin.
- Browser smoke tests must not trigger destructive scanners, active DAST, payment flows, email-producing flows, load tests, or mutating route scans by default.
- Quality Center browser tests should prefer read-only `/api/dashboard/quality/*` endpoints until explicit approval-gated run endpoints exist.

## First Smoke Targets

- Dashboard login page.
- Primary navigation for the active user role.
- Quality Center overview, route inventory, route smoke report, and gate report once `/api/dashboard/quality/*` is implemented.
- A permission-denied path for a role that can view but not run quality operations.
