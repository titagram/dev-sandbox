# Hades Agent Backend Production Deploy Runbook

The repository target architecture for Hades Agent has `frontend/` as its only tracked, repository-owned browser source. Its multi-stage image builds the standalone React 19 application, and nginx serves the compiled SPA plus the exact `/install.sh` and `/install.ps1` application installers. Laravel serves APIs and backend-owned storage paths; it does not build or serve the target browser UI.

A live environment is compliant with that target only after every Task 5 gate in `docs/superpowers/plans/2026-07-14-react-frontend-repository-cutover.md` has passed. Until the Task 5 deployment, browser/API smoke, and external-checkout retirement are recorded complete in the plan and `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`, do not infer live cutover state from the repository layout or this runbook.

Existing `devboard*` Compose filenames, environment names, image names, volume names, router names, and container identifiers are temporary internal compatibility identifiers. They do not indicate frontend ownership and must not be used as a reason to restore the former adjacent frontend checkout or Laravel Vite/Inertia deployment path. The repository Compose definitions have no Node/Vite/Inertia frontend service.

## Required Environment And Fail-Closed Preflight

Load runtime values from the deployment secret manager or protected deployment environment. Never commit them to Git, paste them into runbooks, or print them in command output. The host/domain values are deployment configuration; passwords, application keys, and the BasicAuth hash are secrets.

The production application requires:

- `DEVBOARD_PUBLIC_BASE_URL`
- `DEVBOARD_APP_KEY`
- `DEVBOARD_DB_PASSWORD`
- `DEVBOARD_NEO4J_PASSWORD`
- `DEVBOARD_SESSION_DOMAIN`
- `DEVBOARD_STATEFUL_DOMAINS`
- `DEVBOARD_DASHBOARD_ORIGINS`

The Traefik overlay additionally requires:

- `DEVBOARD_TRAEFIK_HOST`
- `DEVBOARD_TRAEFIK_BASIC_AUTH_USERS`

`DEVBOARD_TRAEFIK_NETWORK`, entrypoint names, and certificate resolver may use their documented deployment defaults or explicit environment values. The repository `.env` is currently insufficient by itself because it does not provide every required production and Traefik value. A deployment must fail closed before Compose interpolation when any required value is absent:

```bash
required=(
  DEVBOARD_PUBLIC_BASE_URL DEVBOARD_APP_KEY DEVBOARD_DB_PASSWORD
  DEVBOARD_NEO4J_PASSWORD DEVBOARD_SESSION_DOMAIN
  DEVBOARD_STATEFUL_DOMAINS DEVBOARD_DASHBOARD_ORIGINS
)
for name in "${required[@]}"; do
  test -n "${!name:-}" || { printf 'required deployment value is unset: %s\n' "$name" >&2; exit 1; }
done
```

Before any Traefik validation or deployment, also run:

```bash
required_proxy=(DEVBOARD_TRAEFIK_HOST DEVBOARD_TRAEFIK_BASIC_AUTH_USERS)
for name in "${required_proxy[@]}"; do
  test -n "${!name:-}" || { printf 'required proxy value is unset: %s\n' "$name" >&2; exit 1; }
done
```

These checks print only a missing variable name, never its value. The default application bind is `127.0.0.1:18000`; PostgreSQL and Neo4j have no production host ports.

## Build And Read-Only Compose Validation

Run the fail-closed preflight first. Validate the relevant base and architecture combinations without creating containers:

```bash
docker compose -f docker-compose.devboard.yaml config --quiet
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml -f docker-compose.devboard.amd64.yaml config --quiet
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml -f docker-compose.devboard.traefik.yaml config --quiet
```

Use the amd64 overlay in the Traefik commands as well when that is the deployment architecture. Build every deployed production image, including the standalone frontend:

```bash
docker compose -f docker-compose.devboard.prod.yaml build app worker scheduler frontend
```

The frontend Docker build uses the committed Yarn lockfile with `yarn install --frozen-lockfile`; `.env*` files are excluded from its build context. The resulting nginx image must contain `index.html`, `favicon.svg`, `install.sh`, and `install.ps1`.

## Database Upgrade

The production PostgreSQL service uses the digest-pinned PostgreSQL 16 pgvector image `pgvector/pgvector:pg16@sha256:1d533553fefe4f12e5d80c7b80622ba0c382abb5758856f52983d8789179f0fb`. Run migrations against a database created from that image so the `vector` extension and Hades search schema are available before deploying vector-enabled search features.

Run migrations before exposing a backend version that requires them:

```bash
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan migrate --force
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan db:seed --class=DevBoardSeeder --force
```

Audit chain constraint deployment is a two-step maintenance operation when upgrading an existing database. First deploy the nullable chain metadata migration, put the application in maintenance mode or pass `--force` during an approved maintenance window, then run:

```bash
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan audit:chain-backfill --dry-run
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan audit:chain-backfill --force
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan audit:verify-chain
```

Do not run the final audit constraint migration until this query returns `0` and `audit:verify-chain` exits successfully:

```sql
select count(*) from audit_logs
where sequence is null or chain_version is null or row_hash is null;
```

The backfill locks the global audit chain, orders legacy rows by `created_at` then `id`, assigns sequence numbers from `1`, initializes `audit_chain_heads.global`, and verifies the chain before returning success. Backups preserve canonical audit chain fields exactly, and restore dry-runs independently validate exported audit chains.

`DevBoardSeeder` is the structural production seeder for roles, permissions, and the agent registry. It does not create users or demo projects. Never run `DemoDevBoardSeeder` in production; avoid `DatabaseSeeder` so a future environment/configuration error cannot opt into demo data.

Create the first administrator through the one-shot command. The password is read only from hidden interactive prompts and is never accepted as an argument:

```bash
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan devboard:bootstrap-admin
```

The command refuses to replace an existing administrator. Do not substitute `db:seed`, Tinker snippets, SQL, or command-line password arguments.

## Start

```bash
docker compose -f docker-compose.devboard.prod.yaml up -d app frontend worker scheduler postgres neo4j
docker compose -f docker-compose.devboard.prod.yaml ps
```

`frontend` serves the React build through nginx. `worker` handles the database queue. `scheduler` runs Laravel `schedule:work`, including retention and search reindex schedules. The backend processes depend on healthy PostgreSQL and Neo4j.

## Traefik Overlay And Route Ownership

The Traefik file is a deployment overlay and expects an existing external network. Run the proxy fail-closed preflight before using it:

```bash
docker compose \
  -f docker-compose.devboard.prod.yaml \
  -f docker-compose.devboard.traefik.yaml \
  config --quiet

docker compose \
  -f docker-compose.devboard.prod.yaml \
  -f docker-compose.devboard.traefik.yaml \
  up -d app frontend worker scheduler postgres neo4j
```

Route precedence is intentional:

| Priority | Owner | Paths | Proxy BasicAuth |
| ---: | --- | --- | --- |
| `130` | frontend nginx | exact `/install.sh` and `/install.ps1` | no |
| `120` | Laravel | `/api/plugin/v1` and `/api/hades/v1` | no; application token authentication applies |
| `100` | Laravel | `/api`, `/sanctum`, and `/storage` | yes |
| `1` | frontend nginx | React catch-all, including `/login`, favicon, and nested browser routes | yes |

The installer exception exposes only the two versioned application installation files served by nginx. It is not a general static-file or directory bypass. Laravel API responses must remain JSON/application responses; React routes must remain nginx SPA responses.

## Smoke Checks

Private checks do not place proxy credentials in process arguments:

```bash
curl -fsS http://127.0.0.1:18000/up
docker compose -f docker-compose.devboard.prod.yaml exec -T postgres pg_isready -U "${DEVBOARD_DB_USERNAME:-devboard}" -d "${DEVBOARD_DB_DATABASE:-devboard}"
docker compose -f docker-compose.devboard.prod.yaml exec -T neo4j cypher-shell -u neo4j -p "$DEVBOARD_NEO4J_PASSWORD" 'RETURN 1 AS ok'
docker compose -f docker-compose.devboard.prod.yaml exec -T app su-exec www-data php artisan schedule:list
docker compose -f docker-compose.devboard.prod.yaml ps
```

Public acceptance uses the protected browser/session or another approved credential mechanism and verifies:

- HTTP redirects to HTTPS and TLS is valid;
- `/login`, `/favicon.svg`, and a hard refresh of a nested project route return the React application/static asset, not a Laravel Inertia page or 404;
- login succeeds and a representative project page renders;
- `/api/dashboard/me` reaches Laravel and returns the expected JSON success or application-authentication response, never nginx HTML or 405;
- `/api/hades/v1/health` reaches the Hades Laravel route with its expected token/authentication contract, never the React shell;
- `/install.sh` and `/install.ps1` are retrievable without proxy BasicAuth, have application installer content only, and do not expose directory listings;
- no browser request targets a loopback API origin.

The protected `/up` route is checked through loopback so proxy credentials never appear in `curl` arguments or process listings.

## Frontend-Only Cutover From The Current Legacy Development Compose Stack

This procedure replaces only the currently orphaned legacy frontend container after the repository-owned image has passed build validation. It must not run migrations, seeders, or backend/container restarts.

1. Tag the running frontend image outside the release tag so it can be restored without rebuilding:

   ```bash
   current_frontend_image="$(docker inspect --format '{{.Image}}' devboard-frontend-1)"
   test -n "$current_frontend_image"
   docker image inspect "$current_frontend_image" >/dev/null
   docker image tag "$current_frontend_image" hades-agent-frontend:pre-cutover-20260714
   test "$(docker image inspect --format '{{.Id}}' hades-agent-frontend:pre-cutover-20260714)" = "$current_frontend_image"
   ```

2. Archive the external source outside Git and verify the archive before changing the container:

   ```bash
   install -d -m 700 /home/ubuntu/backups/devboard
   tar --exclude='.git' --exclude='node_modules' --exclude='build' \
     -C /home/ubuntu -czf /home/ubuntu/backups/devboard/emergent-frontend-pre-cutover-20260714.tar.gz \
     emergent_devboard_frontend/frontend
   tar -tzf /home/ubuntu/backups/devboard/emergent-frontend-pre-cutover-20260714.tar.gz \
     | grep -q 'frontend/src/App.tsx'
   ```

3. Build and recreate only `frontend` with the current development and Traefik files:

   ```bash
   docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml build frontend
   docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml \
     up -d --no-deps frontend
   ```

4. Require every private and public smoke gate above, including favicon, login, a nested route hard refresh, API routing, and both installers. Confirm `app`, `worker`, `scheduler`, `postgres`, and `neo4j` container IDs did not change.

5. Remove `/home/ubuntu/emergent_devboard_frontend` only after all gates pass and the rollback tag/archive have been verified. Repeat public smoke after removal.

If any smoke gate fails after the new frontend is recreated, immediately execute the frontend-only rollback below, verify the restored frontend, and retain the external checkout. Do not proceed to external-checkout removal after a failed gate.

## Operations And Rollback

Normal backend operations remain:

```bash
docker compose -f docker-compose.devboard.prod.yaml logs --tail=120 app worker scheduler frontend
docker compose -f docker-compose.devboard.prod.yaml up -d --force-recreate worker scheduler
```

Every Task 5 smoke failure triggers this frontend-only rollback. Restore only the saved frontend image and recreate only that service:

```bash
docker image tag hades-agent-frontend:pre-cutover-20260714 devboard-frontend
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml \
  up -d --no-deps --no-build --force-recreate frontend
```

Do not run migrations, seeders, database restores, or recreate backend/data services for a frontend rollback. For a full backend release, set `DEVBOARD_BACKEND_IMAGE` to an immutable release tag and use only the separately approved backward-compatible database rollback procedure.

## `ai-sandbox` Scope

`ai-sandbox/` is repository-local bootstrap support: operating rules, environment/project metadata, local logbooks, and derived local inspection aids. It is not the product runtime and must not become a second operational source of truth. The Hades backend is the shared source of truth for project memory, wiki content, and project state. Keep shared facts there; keep this runbook authoritative for deployment operations; avoid copying mutable backend state into sandbox wiki or config files.
