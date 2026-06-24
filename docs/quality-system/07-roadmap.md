# Quality System Roadmap

## Phase 1: Documentation And Registries

- `verified_from_code`: This `docs/quality-system/` directory starts the shared quality language.
- `inferred`: Add `backend/config/quality/` registries for truth, routes, actors, authorization matrix, gates, and scanners.
- `verified_from_code`: YAML registries are the initial format, backed by a direct `symfony/yaml` dependency.

## Phase 2: Report Primitives

- `verified_from_code`: Serializable report value objects and a writer for JSON and Markdown exist.
- `verified_from_code`: Reports are written under `backend/var/quality/reports/` and generated report paths are ignored in Git.
- `verified_from_code`: Pest/PHPUnit coverage verifies report structure before route or scanner commands depend on it.

## Phase 3: Route Inventory

- `verified_from_code`: `quality:route-inventory` exists as a read-only Laravel Artisan command.
- `verified_from_code`: Inventory uses Laravel router metadata and does not execute application routes.
- `verified_from_code`: Inventory identifies unconfigured routes and parameter provider gaps.

## Phase 4: Safe Route Smoke

- `verified_from_code`: `quality:route-smoke --actor=guest` exists for configured `SAFE_READ` routes.
- `verified_from_code`: Route smoke skips unknown, mutating, destructive, side-effecting, and underconfigured routes by default.
- `verified_from_code`: Route smoke emits findings for failures and warnings in the shared report shape.

## Phase 5: Gate Evaluation

- `verified_from_code`: `quality:check-gates --gate=pull_request` exists.
- `verified_from_code`: Gates consume report JSON and produce deterministic pass/fail decisions.
- `verified_from_code`: Composer scripts call route inventory, route smoke, tests, audit, static analysis, and gate checks.

## Phase 6: Optional Tooling

- `verified_from_code`: PHPStan is installed and scoped to the quality layer; broader Laravel static analysis remains future hardening.
- `inferred`: Add Playwright only in the active frontend integration branch.
- `inferred`: Keep Semgrep, Trivy, ZAP, Nuclei, active DAST, load tests, payment tests, and email-producing tests disabled until explicitly configured.

## Phase 7: Dashboard API And Quality Center

- `verified_from_code`: `/api/dashboard/quality/*` JSON endpoints exist for overview, current state, reports, route inventory, route smoke, gate reports, and roadmap data using the generated frontend contract shapes.
- `verified_from_code`: `POST /api/dashboard/quality/runs` exists for whitelisted Quality tools and is restricted to Admin/Sysadmin roles; route smoke is forced to `actor=guest` and mutating/destructive execution flags are rejected.
- `verified_from_code`: `/api/dashboard/{login,me,logout}` exists for the generated frontend HTTP adapter's session-auth lifecycle.
- `verified_from_code`: The generated frontend already contains Quality Center adapter methods and mock data expectations for this future surface.
- `inferred`: Remaining generated frontend HTTP integration work covers the broader dashboard resources: projects, kanban, tasks, runs, wiki, graph, artifacts, admin devices/tokens, and system endpoints under `/api/dashboard/*`.
