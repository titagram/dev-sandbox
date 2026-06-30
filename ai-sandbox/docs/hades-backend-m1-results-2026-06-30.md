# Hades Backend M1 Results - 2026-06-30

## Outcome

M1 backend is implemented and verified for the Laravel backend under `/api/hades/v1`.

The backend Codex agent was resumed over SSH and was given the M1 scope twice. It stalled in `Working` before creating code, including after a reduced instruction to create only the red feature test. Implementation was completed directly over SSH by the frontend-side agent, with this result document recording the coordination and outcome.

## Implemented API

- `GET /api/hades/v1/health`
  - Public Laravel endpoint.
  - Returns protocol version, service name, status, route inventory, and server time.
- `POST /api/hades/v1/token/verify`
  - Requires a Hades bootstrap bearer token.
  - Validates `project_id`, revocation, expiry, secret hash, project scope, and `hades.bootstrap` scope.
  - Does not create an agent.
- `POST /api/hades/v1/agents/register`
  - Requires a Hades bootstrap bearer token.
  - Accepts `project_id`, external/local `agent_id`, `label`, `platform`, `version`, and declared `capabilities`.
  - Creates or updates a backend internal agent and stores the client-provided id as `external_agent_id`.
  - Intersects declared capabilities with backend-supported/granted capabilities.
  - Returns a derived Hades agent token for future operational calls.
- `GET /api/hades/v1/capabilities`
  - Requires a Hades agent bearer token through `hades.agent` middleware.
  - Returns project id, backend agent id, external agent id, effective capabilities, M1 limits, policy flags, routes, and server time.

## Token Policy

- Bootstrap token format: `hades_bootstrap_{ulid}|{secret}`.
- Agent token format: `hades_agent_{ulid}|{secret}`.
- Secrets are stored only as SHA-256 hashes.
- Tokens are revocable via `revoked_at` and expirable via `expires_at`.
- Bootstrap tokens are project-scoped and registration-only.
- Agent tokens are project/agent-scoped and required for operational M1 routes.
- Hades API intentionally does not require the plugin `X-DevBoard-Protocol` header because the Hades client sends standard bearer JSON calls.

## Capability Policy

Supported M1 capabilities are currently:

- `read_files`
- `sync_git_tree`
- `populate_backend_ast`

M1 explicitly reports these future surfaces as unavailable:

- `memory: false`
- `jobs: false`
- `artifacts: false`
- `persephone: false`
- `workspace_binding_required: false`

`workspace_binding_id` is not accepted from the client in M1. Workspace binding remains a backend-derived M2 concern.

## Files Touched

- `backend/routes/api.php`
- `backend/bootstrap/app.php`
- `backend/database/migrations/2026_06_30_000001_create_hades_m1_tables.php`
- `backend/app/Http/Controllers/Hades/HealthController.php`
- `backend/app/Http/Controllers/Hades/TokenVerifyController.php`
- `backend/app/Http/Controllers/Hades/AgentRegisterController.php`
- `backend/app/Http/Controllers/Hades/CapabilitiesController.php`
- `backend/app/Http/Middleware/AuthenticateHadesAgentToken.php`
- `backend/app/Services/Hades/HadesCapabilityPolicy.php`
- `backend/app/Services/Hades/HadesTokenException.php`
- `backend/app/Services/Hades/HadesTokenService.php`
- `backend/app/Models/HadesBootstrapToken.php`
- `backend/app/Models/HadesAgent.php`
- `backend/app/Models/HadesAgentToken.php`
- `backend/tests/Feature/Hades/HadesM1ApiTest.php`
- `docker-compose.devboard.traefik.yaml`

## Verification

### Red Test

Initial focused test run failed as expected before implementation:

- `GET /api/hades/v1/health` returned 404.
- Hades token tests failed because `hades_bootstrap_tokens` did not exist.
- Summary: `7 failed`, `1 assertion`.

Command:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades/HadesM1ApiTest.php'
```

### Green Tests

Focused Hades M1 suite:

- `7 passed`
- `63 assertions`

Regression with existing plugin auth suite:

- `14 passed`
- `88 assertions`

Command:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades/HadesM1ApiTest.php tests/Feature/PluginAuthTest.php'
```

Additional verification:

- PHP lint passed for all new/changed Hades PHP files and migration.
- `vendor/bin/pint --dirty` passed with `0 files` changed.
- `git diff --check` passed.
- `php artisan route:list --path=hades` shows 4 Hades routes.
- Runtime migration applied with `php artisan migrate --force` and completed `2026_06_30_000001_create_hades_m1_tables`.
- Public smoke after Traefik router update:
  - `https://home-sweet-home.cloud/api/hades/v1/health` returned HTTP 200 with Hades JSON health payload.
  - `https://home-sweet-home.cloud/api/hades/v1/capabilities` without bearer returned HTTP 401 with Laravel JSON `Hades agent token is required`, confirming Traefik BasicAuth no longer blocks Hades API paths.

## Traefik Decision

`/api/hades/v1` needs the same agent-facing reachability as `/api/plugin/v1`, so `docker-compose.devboard.traefik.yaml` now defines a dedicated `devboard-hades` router with higher priority than the generic `/api` router and without `devboard-basic-auth` middleware.

## Follow-Up Items

- Add an admin or setup path to create/revoke bootstrap tokens instead of relying on direct DB insertion.
- Add M2 workspace binding generated server-side after agent registration.
- Extend capability response as M2-M4 endpoints land, keeping disabled future surfaces explicit until implemented.
- Add Hades client integration smoke against the public backend using a real bootstrap token once token provisioning exists.
