# Frontend live cutover — Phase A deployment report

Date: 2026-07-14

Status: **PASS with pre-existing operational warnings recorded below.**

## Scope and safety

- Source branch: `codex/frontend-cutover-workqueue-fix` at `f7c4c2aae641879ecf7c215fe83c03ba987785e4`.
- Live branch fast-forwarded from `f4edab3ad44ff62626f775c2166fcbed805f61fb` to `f7c4c2aae641879ecf7c215fe83c03ba987785e4` with `git merge --ff-only`.
- No database command, migration, seeder, backend/data restart, commit, or push was executed.
- The pre-existing dirty file `backend/vendor/pestphp/pest/.temp/test-results` retained SHA-256 `771ba8deba4db3f7355b1061e34a15c0270f2882b8eae8762d7f94395d8c0a4e` across the fast-forward.
- `/home/ubuntu/emergent_devboard_frontend` remains present; Phase A did not remove it.

## Preflight verification

- Source tracked-clean and `git diff --check`: PASS.
- Frontend tests: 6 suites, 43 tests, all PASS.
- `corepack yarn tsc --noEmit`: PASS.
- Production frontend build: PASS.
- `PluginSharedMemoryAndWorkQueueTest.php`: 19 tests, 126 assertions, PASS.
- Work-item sibling set: 46 tests, 326 assertions, PASS.
- Pest test-result artifact restored after backend tests.

Warnings: CRA build reports the existing `react-hooks/exhaustive-deps` warning in `CommandPalette.tsx`. Backend tests report the known missing-worktree-`.env` warning; tests used only the documented process-local dummy `APP_KEY`.

## Rollback material

The original container referenced image `sha256:874bcab07ded…`, but Docker had already garbage-collected both the image object and a required layer, so direct tagging and `docker commit` of the running container were impossible.

The approved recovery path succeeded:

- Private runtime snapshot: `/home/ubuntu/backups/devboard/frontend-runtime-pre-cutover-20260714`, mode `0700`.
- Snapshot contains the live nginx HTML tree and `default.conf`.
- Recovery image rebuilt on the pinned nginx base from the exact runtime snapshot: `hades-agent-frontend:pre-cutover-20260714`, image prefix `sha256:e5cc94018522`.
- Recovery image `nginx -t`, legacy title, and both installers: PASS.
- Legacy runtime favicon absence was verified as pre-existing; no favicon was synthesized into the recovery image.
- External source archive: `/home/ubuntu/backups/devboard/emergent-frontend-pre-cutover-20260714.tar.gz`, mode `0600`; `src/App.tsx` validation: PASS.

## Deployment

- Traefik host was read from the live frontend router label.
- BasicAuth users value was read from the app middleware label.
- Both values were validated non-empty and supplied only as process environment to Compose; neither was printed or persisted.
- Merged development + Traefik Compose config: PASS.
- Only `frontend` was built/recreated with `--no-deps`.
- New frontend container ID prefix: `dc81f02e0a59`.
- New frontend image prefix: `sha256:5bd6108e3a3f`.
- Compose working directory label is `/home/ubuntu/dev-sandbox`.
- New nginx config, Hades title, favicon, installers, `/login`, and nested SPA hard-refresh route: PASS.

## Runtime invariants

- App container unchanged: `73d4511a5bf6…`, running.
- PostgreSQL container unchanged: `4b94a368fab9…`, running and healthy.
- Neo4j container unchanged: `b2d81184058d…`, running and healthy.
- Worker and scheduler were absent before the cutover and remain absent. They were not started.
- Database container ID is unchanged.
- External checkout remains present.

## Public/server smoke

| Endpoint | Status | Content type | Result |
| --- | ---: | --- | --- |
| `http://home-sweet-home.cloud/` | 301 | none | HTTPS redirect PASS |
| `/login` | 401 | `text/plain` | Expected proxy BasicAuth without credentials |
| `/favicon.svg` | 401 | `text/plain` | Expected proxy BasicAuth without credentials |
| `/install.sh` | 200 | `text/plain` | Hades installer marker PASS |
| `/install.ps1` | 200 | `text/plain` | Hades installer marker PASS |
| `/api/hades/v1/health` | 200 | `application/json` | Laravel/Hades routing PASS |
| `/api/dashboard/me` (public) | 401 | `text/plain` | Expected proxy BasicAuth without credentials |
| `/api/dashboard/me` (backend loopback) | 401 | `application/json` | Laravel auth semantics PASS |

API responses were verified not to be nginx HTML and not to return 405. Browser-authenticated QA and retirement of the external checkout remain intentionally pending for the next phase.

## Residual operational gap

`worker` and `scheduler` containers were already absent before this frontend-only operation. Their absence is unrelated to the cutover and must be handled separately; Phase A intentionally did not start or restart backend services.
