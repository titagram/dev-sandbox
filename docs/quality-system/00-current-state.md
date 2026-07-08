# DevBoard Quality System Current State

## Scope

- `developer_provided`: DevBoard is the control plane. It does not contain the source code of target repositories being analyzed.
- `developer_provided`: Tests and scanners for target repository code must run near that target code through the local plugin or local agent. DevBoard receives reports, evidence, artifacts, and gate status, then normalizes and displays them.
- `verified_from_code`: The current repository contains a Laravel backend, an Inertia/React dashboard, a Python CLI/MCP plugin, an analyzer package, Docker Compose runtime files, PostgreSQL configuration, and Neo4j integration code.

## Backend Stack

- `verified_from_code`: `backend/composer.json` requires PHP `^8.3`, `laravel/framework` `^13.8`, `inertiajs/inertia-laravel` `^3.1`, `laravel/sanctum` `^4.3`, and `laudis/neo4j-php-client` `^3.5`.
- `verified_from_code`: `backend/composer.json` has direct dev dependencies for Pest, Pest Laravel plugin, PHPUnit, Laravel Pint, PHPStan, Mockery, Collision, Laravel Pail, and Laravel Pao.
- `verified_from_code`: `backend/composer.json` declares `symfony/yaml` as a direct dependency for quality registry parsing.
- `verified_from_code`: `backend/phpstan.neon.dist` runs an initial level-0 PHPStan analysis over `app`, `bootstrap/app.php`, and `config`.
- `verified_from_code`: Host PHP is not available in this session; `php artisan route:list --except-vendor` and quality checks are verified through the running Docker `app` container.

## Frontend Stack

- `verified_from_code`: `backend/package.json` uses Vite with `vite build`, `vite`, `@vitejs/plugin-react`, `@inertiajs/react`, React, React DOM, Tailwind, and lucide-react.
- `verified_from_code`: The emergent.sh frontend checkout at `/home/ubuntu/emergent_devboard_frontend` is a separate Create React App plus CRACO project under `frontend/`, not a Vite project under `app/`.
- `verified_from_code`: The emergent.sh frontend handoff documents a typed adapter boundary with mock data by default and real HTTP calls under `/api/dashboard/*`.
- `verified_from_code`: Host Node is installed at `/opt/node-v22.12.0` and exposed through `/usr/local/bin/node`, `/usr/local/bin/npm`, `/usr/local/bin/npx`, and `/usr/local/bin/corepack`; the backend Vite build passes with Node `v22.12.0`.

## Existing Route Surface

- `verified_from_code`: `backend/routes/web.php` exposes browser-facing Inertia routes such as `/kanban`, `/runs`, `/runs/{run}`, `/wiki`, `/graph`, `/artifacts`, `/system`, `/admin/plugin-tokens`, and session login/logout routes.
- `verified_from_code`: `backend/routes/api.php` exposes `/api/plugin/v1/*` endpoints for the local Python CLI/MCP plugin.
- `verified_from_code`: The initial Docker route-list inspection showed no `/api/dashboard/*` route group; Task 9 later added `/api/dashboard/quality/*` routes through the authenticated browser dashboard route stack, and the current integration slice adds `/api/dashboard/{login,me,logout}` for the generated frontend adapter.
- `developer_provided`: The browser UI must not use `/api/plugin/v1`; browser integration must use dashboard adapter endpoints under `/api/dashboard/...`.

## Existing Tests

- `verified_from_code`: `backend/tests/Feature` includes coverage for dashboard auth/slices, plugin auth/rate limits/repository API, run lifecycle, Genesis upload, Delta Sync, graph import, artifact retention, audit export, wiki revisions, and system maintenance.
- `verified_from_code`: `backend/tests/Unit` includes example unit coverage and Genesis graph Cypher tests.
- `verified_from_code`: Existing backend tests are Pest/PHPUnit tests.

## Quality-Layer State

- `verified_from_code`: `backend/config/quality/` contains initial YAML registries for route classifications, actors, truth entries, authorization expectations, gates, and scanner readiness.
- `verified_from_code`: `backend/app/Quality/` contains report primitives, route inventory/smoke services, actor registry support, and gate evaluation services.
- `verified_from_code`: `backend/app/Console/Commands/Quality/` registers `quality:route-inventory`, `quality:route-smoke`, and `quality:check-gates`.
- `verified_from_code`: `backend/routes/web.php` exposes authenticated JSON endpoints for `/api/dashboard/quality/overview`, `/api/dashboard/quality/current-state`, `/api/dashboard/quality/reports`, `/api/dashboard/quality/route-inventory`, `/api/dashboard/quality/route-smoke`, `/api/dashboard/quality/gates/{gate}`, `/api/dashboard/quality/roadmap`, and `POST /api/dashboard/quality/runs`.
- `verified_from_code`: `POST /api/dashboard/quality/runs` is limited to Admin/Sysadmin dashboard roles, supports only whitelisted safe quality tools, and does not allow mutating or destructive route execution flags.
- `verified_from_code`: Generated quality reports are written under ignored `backend/var/quality/reports/`.
- `verified_from_code`: YAML registry parsing uses the direct `symfony/yaml` dependency added during the quality route inventory slice.
