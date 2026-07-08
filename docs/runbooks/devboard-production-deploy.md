# DevBoard Production Deploy Runbook

This runbook covers the production-oriented Docker profile in `docker-compose.devboard.prod.yaml`.
The development profile remains `docker-compose.devboard.yaml`.

## Scope

- Backend image: builds Laravel dependencies into the image and serves PHP through PHP-FPM plus nginx.
- Frontend image: builds from `/home/ubuntu/emergent_devboard_frontend/frontend`.
- Data services: PostgreSQL and Neo4j are internal Compose services with named volumes and no host ports.
- Public ingress: terminate TLS in your reverse proxy and route browser/API traffic to the two containers below.

## Required Environment

Set these variables before running Compose:

```bash
export DEVBOARD_PUBLIC_BASE_URL='https://example.com'
export DEVBOARD_APP_KEY='base64:replace-with-laravel-key'
export DEVBOARD_DB_PASSWORD='replace-with-long-random-password'
export DEVBOARD_NEO4J_PASSWORD='replace-with-long-random-password'
export DEVBOARD_SESSION_DOMAIN='example.com'
export DEVBOARD_STATEFUL_DOMAINS='example.com'
export DEVBOARD_DASHBOARD_ORIGINS='https://example.com'
```

Optional bind ports for local smoke testing:

```bash
export DEVBOARD_APP_BIND='127.0.0.1:18000'
export DEVBOARD_FRONTEND_BIND='127.0.0.1:18080'
```

Do not bind PostgreSQL or Neo4j to public host ports. Use `docker compose exec` for administrative access.

## Build

```bash
docker compose -f docker-compose.devboard.prod.yaml build app worker frontend
```

## Database Initialization Or Upgrade

Run migrations before starting or immediately after the first start, depending on your maintenance window:

```bash
docker compose -f docker-compose.devboard.prod.yaml run --rm app php artisan migrate --force
docker compose -f docker-compose.devboard.prod.yaml run --rm app php artisan db:seed --class=DevBoardSeeder --force
```

The seed command is expected for the controlled model/agent registry and default DevBoard records.

## Start

```bash
docker compose -f docker-compose.devboard.prod.yaml up -d app worker frontend postgres neo4j
```

## Reverse Proxy Routing

Route API and Laravel-owned paths to `app:8000`:

- `/api`
- `/sanctum`
- `/storage`
- `/up`

Route all other browser paths to `frontend:80`.

The frontend build uses `DEVBOARD_PUBLIC_BASE_URL` as `REACT_APP_API_BASE_URL`, so the public origin must be stable before building the image.

## Smoke Checks

```bash
docker compose -f docker-compose.devboard.prod.yaml ps
curl -fsS http://127.0.0.1:18000/up
curl -fsS http://127.0.0.1:18080/
docker compose -f docker-compose.devboard.prod.yaml exec -T postgres pg_isready -U "${DEVBOARD_DB_USERNAME:-devboard}" -d "${DEVBOARD_DB_DATABASE:-devboard}"
docker compose -f docker-compose.devboard.prod.yaml exec -T neo4j cypher-shell -u neo4j -p "$DEVBOARD_NEO4J_PASSWORD" 'RETURN 1 AS ok'
```

For a public deployment, also check:

```bash
curl -fsS "$DEVBOARD_PUBLIC_BASE_URL/up"
curl -I "$DEVBOARD_PUBLIC_BASE_URL/"
```

## Operations

Read logs:

```bash
docker compose -f docker-compose.devboard.prod.yaml logs --tail=120 app
docker compose -f docker-compose.devboard.prod.yaml logs --tail=120 worker
```

Run Artisan commands:

```bash
docker compose -f docker-compose.devboard.prod.yaml exec app php artisan about
```

Restart workers after deploying a new image:

```bash
docker compose -f docker-compose.devboard.prod.yaml up -d --force-recreate worker
```

## Rollback

Pin `DEVBOARD_BACKEND_IMAGE` and `DEVBOARD_FRONTEND_IMAGE` to immutable image tags during real deployments. To roll back, restore the previous tags and recreate app, worker, and frontend:

```bash
docker compose -f docker-compose.devboard.prod.yaml up -d --force-recreate app worker frontend
```
