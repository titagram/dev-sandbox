# DevBoard Production Deploy Runbook

This runbook uses `docker-compose.devboard.prod.yaml`. The Laravel/Inertia frontend is built from `backend/` into the backend image and served by the same nginx process; production has no dependency on an adjacent repository.

## Required Environment

Load these values from the deployment secret manager, not from a committed `.env` file:

```bash
export DEVBOARD_PUBLIC_BASE_URL='https://devboard.example.com'
export DEVBOARD_APP_KEY='base64:replace-with-a-generated-laravel-key'
export DEVBOARD_DB_PASSWORD='replace-with-a-long-random-password'
export DEVBOARD_NEO4J_PASSWORD='replace-with-a-long-random-password'
export DEVBOARD_SESSION_DOMAIN='devboard.example.com'
export DEVBOARD_STATEFUL_DOMAINS='devboard.example.com'
export DEVBOARD_DASHBOARD_ORIGINS='https://devboard.example.com'
```

The default application bind is `127.0.0.1:18000`. PostgreSQL and Neo4j have no host ports.

## Build And Validate

```bash
docker compose -f docker-compose.devboard.prod.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml build app worker scheduler
```

## Database Upgrade

Run migrations before exposing the new application version:

```bash
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan migrate --force
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan db:seed --class=DevBoardSeeder --force
```

`DevBoardSeeder` is now the structural production seeder for roles, permissions, and the agent registry. It does not create users or demo projects. Never run `DemoDevBoardSeeder` in production; avoid `DatabaseSeeder` so a future environment/configuration error cannot opt into demo data.

Create the first administrator through the one-shot command. The password is read only from hidden interactive prompts and is never accepted as an argument:

```bash
docker compose -f docker-compose.devboard.prod.yaml run --rm app su-exec www-data php artisan devboard:bootstrap-admin
```

The command refuses to replace an existing administrator. Do not substitute `db:seed`, Tinker snippets, SQL, or command-line password arguments.

## Start

```bash
docker compose -f docker-compose.devboard.prod.yaml up -d app worker scheduler postgres neo4j
docker compose -f docker-compose.devboard.prod.yaml ps
```

`worker` handles the database queue. `scheduler` runs Laravel `schedule:work`, including retention and search reindex schedules. Both services have process liveness healthchecks and depend on healthy PostgreSQL and Neo4j.

## Traefik Overlay

The Traefik file is an override for production only. It expects an existing external network and routes the Laravel API and Inertia frontend to `app:8000`.

```bash
export DEVBOARD_TRAEFIK_HOST='devboard.example.com'
export DEVBOARD_TRAEFIK_NETWORK='traefik_default'
export DEVBOARD_TRAEFIK_CERT_RESOLVER='le'
export DEVBOARD_TRAEFIK_BASIC_AUTH_USERS='operator:<htpasswd-hash-from-secret-manager>'

docker compose \
  -f docker-compose.devboard.prod.yaml \
  -f docker-compose.devboard.traefik.yaml \
  config --quiet

docker compose \
  -f docker-compose.devboard.prod.yaml \
  -f docker-compose.devboard.traefik.yaml \
  up -d app worker scheduler postgres neo4j
```

The plugin and Hades API routers bypass proxy basic auth because they use application token authentication. All other HTTPS paths use the configured basic-auth middleware. Do not combine the Traefik override with `docker-compose.devboard.yaml`.

## Smoke Checks

```bash
curl -fsS http://127.0.0.1:18000/up
docker compose -f docker-compose.devboard.prod.yaml exec -T postgres pg_isready -U "${DEVBOARD_DB_USERNAME:-devboard}" -d "${DEVBOARD_DB_DATABASE:-devboard}"
docker compose -f docker-compose.devboard.prod.yaml exec -T neo4j cypher-shell -u neo4j -p "$DEVBOARD_NEO4J_PASSWORD" 'RETURN 1 AS ok'
docker compose -f docker-compose.devboard.prod.yaml exec -T app su-exec www-data php artisan schedule:list
docker compose -f docker-compose.devboard.prod.yaml ps
```

The Traefik web router protects `/up` with the configured basic-auth middleware. This runbook intentionally checks health through the loopback bind so proxy credentials never appear in `curl` arguments or process listings. Do not use an unauthenticated public `/up` request as a deployment check; validate public TLS and login through the normal authenticated access path.

## Operations And Rollback

```bash
docker compose -f docker-compose.devboard.prod.yaml logs --tail=120 app worker scheduler
docker compose -f docker-compose.devboard.prod.yaml up -d --force-recreate worker scheduler
```

Set `DEVBOARD_BACKEND_IMAGE` to an immutable release tag in real deployments. Roll back by restoring the previous image tag, running only backward-compatible migration procedures, and recreating `app`, `worker`, and `scheduler`.
