# DevBoard Test Taxonomy

## Existing Test Types

- `verified_from_code`: Backend feature tests live under `backend/tests/Feature`.
- `verified_from_code`: Backend unit tests live under `backend/tests/Unit`.
- `verified_from_code`: Pest/PHPUnit is available as direct backend test tooling.
- `verified_from_code`: The current backend frontend build command is `npm run build` under `backend/`.
- `verified_from_code`: The generated emergent.sh frontend has a `craco test` script but no test files in `frontend/src` at intake time.

## Quality-Layer Test Classes

- `inferred`: Report writer tests should verify JSON and Markdown report structure without coupling scanner-specific behavior to formatting code.
- `inferred`: Route inventory tests should assert metadata extraction without executing application routes.
- `inferred`: Route smoke tests should execute only configured `SAFE_READ` routes and must skip unknown, mutating, destructive, externally side-effecting, or underconfigured routes.
- `inferred`: Gate tests should consume report JSON and assert pass/fail decisions from deterministic inputs.
- `inferred`: Dashboard API tests should verify JSON shape, authentication, authorization, and safe defaults independently from the browser UI.

## Target Repository Tests

- `developer_provided`: Tests for target source code must run near the target repository through the plugin or local agent.
- `inferred`: DevBoard should store target test outputs as reports, evidence, and artifacts rather than attempting to import and execute target source code inside the backend.
- `inferred`: The plugin-side contract should include command, environment, commit or snapshot identifiers, tool version, exit status, summary counts, and artifact references.

## Browser Tests

- `verified_from_code`: Current DevBoard backend uses Inertia/React and Vite.
- `verified_from_code`: The generated frontend is a separate CRA/CRACO application.
- `inferred`: Browser tests should belong to the frontend integration branch that owns the active browser shell, not to the plugin API.
- `needs_verification`: Playwright is not declared in current `backend/package.json` or the generated frontend `frontend/package.json`.

## Static And Security Tests

- `verified_from_code`: Laravel Pint is a direct backend dev dependency.
- `verified_from_code`: PHPStan is a direct backend dev dependency and `composer quality:static` runs it over the quality layer.
- `inferred`: Composer audit can be wired earlier than external scanners because it is package metadata based and non-destructive.
- `inferred`: Semgrep, Trivy, ZAP, Nuclei, browser crawlers, load tests, and active probes should remain opt-in until installed, configured, and explicitly allowed.
