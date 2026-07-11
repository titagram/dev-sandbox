# DevBoard Docker

## Compose Profiles

- Development: `docker-compose.devboard.yaml`
- Development amd64 override: `docker-compose.devboard.amd64.yaml`
- Production: `docker-compose.devboard.prod.yaml`
- Production-only Traefik override: `docker-compose.devboard.traefik.yaml`

The development stack binds all published ports to `127.0.0.1` by default. The production stack publishes only the application on `127.0.0.1:18000`; PostgreSQL and Neo4j stay internal.

## Development

Provide non-production local credentials through the shell or a root `.env` file. The root `.env` file is ignored by Git and lets Compose commands reuse the local runtime credentials:

```bash
export APP_KEY='base64:replace-with-a-local-key'
export DB_PASSWORD=  # set your local PostgreSQL password
export NEO4J_PASSWORD=  # set your local Neo4j password

docker compose -f docker-compose.devboard.yaml up -d app worker scheduler node postgres neo4j
```

The root `.env` must define `APP_KEY`, `DB_PASSWORD`, and `NEO4J_PASSWORD`. Do not add credentials to the Compose file or commit the local `.env`.

Default endpoints are:

- Laravel: `http://127.0.0.1:18000`
- Vite: `http://127.0.0.1:15173`
- PostgreSQL: `127.0.0.1:15432`
- Neo4j HTTP: `http://127.0.0.1:17474`
- Neo4j Bolt: `bolt://127.0.0.1:17687`

Override a host or port independently with `DEVBOARD_APP_BIND`, `DEVBOARD_APP_PORT`, `DEVBOARD_VITE_BIND`, `DEVBOARD_VITE_PORT`, `DEVBOARD_POSTGRES_BIND`, `DEVBOARD_POSTGRES_PORT`, `DEVBOARD_NEO4J_BIND`, `DEVBOARD_NEO4J_HTTP_PORT`, and `DEVBOARD_NEO4J_BOLT_PORT`.

The demo seeder is development/test-only:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan migrate:fresh --seed --seeder=DatabaseSeeder --force
```

## Production

The production image builds the Vite assets from `backend/` and serves Laravel plus Inertia through nginx on port `8000`. It runs the same image as three services: `app`, `worker`, and `scheduler`.

Use `docs/runbooks/devboard-production-deploy.md` for migration, safe admin-bootstrap gating, Traefik, smoke checks, and rollback. The Traefik override must be layered on `docker-compose.devboard.prod.yaml`, never on the development compose.

## Destructive Acceptance Harnesses

The runtime and queue-fault harnesses run under dedicated Compose project names and delete only their isolated volumes during cleanup. They still require explicit acceptance flags when launched manually:

```bash
DEVBOARD_RUNTIME_ACCEPTANCE=1 scripts/devboard_runtime_acceptance.sh
DEVBOARD_QUEUE_FAULT_ACCEPTANCE=1 scripts/devboard_queue_fault_harness.sh
```

The queue-fault harness generates and locks a unique Compose project by default. A custom `DEVBOARD_QUEUE_FAULT_PROJECT` additionally requires `DEVBOARD_QUEUE_FAULT_ALLOW_PROJECT_OVERRIDE=1`; reusing existing Docker resources requires the separate destructive confirmation `DEVBOARD_QUEUE_FAULT_ALLOW_PROJECT_REUSE=1`.
