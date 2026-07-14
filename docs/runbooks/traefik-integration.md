# Hades integration with the shared server Traefik

## Read this first

The `traefik` container on `ubuntu@[IP_ADDRESS]` is shared infrastructure. It routes Hades and unrelated sites. The Hades repository must never own, stop, recreate, or remove that container.

The host-level runbook is `/home/ubuntu/traefik-readme.md`. Read it before changing the shared proxy itself.

## Ownership map

| Resource | Owner | Where to change it |
| --- | --- | --- |
| Traefik process, ports 80/443, Docker provider, ACME | Host | `/home/ubuntu/traefik-readme.md` |
| ACME state | Host | `/home/ubuntu/acme.json` |
| Shared proxy network | Host | Docker network `traefik_default` |
| Hades routers, middleware and target ports | Hades | `docker-compose.devboard.traefik.yaml` |
| Hades application services and data volumes | Hades | `docker-compose.devboard.yaml` |
| Deployment variables and secrets | Host/Hades deployment | ignored `/home/ubuntu/dev-sandbox/.env` |

## Canonical server deployment

The ignored server `.env` must contain these keys:

```dotenv
COMPOSE_FILE=docker-compose.devboard.yaml:docker-compose.devboard.traefik.yaml
COMPOSE_PROJECT_NAME=devboard
DEVBOARD_TRAEFIK_HOST=home-sweet-home.cloud
DEVBOARD_TRAEFIK_NETWORK=traefik_default
DEVBOARD_TRAEFIK_HTTP_ENTRYPOINT=web
DEVBOARD_TRAEFIK_HTTPS_ENTRYPOINT=websecure
DEVBOARD_TRAEFIK_CERT_RESOLVER=le
DEVBOARD_TRAEFIK_BASIC_AUTH_USERS=<htpasswd entry; never commit this value>
```

The same `.env` contains the required application and database secrets. Keep it mode `0600` and never copy its values into documentation, Git, logs, or chat.

From `/home/ubuntu/dev-sandbox`:

```bash
docker compose config --quiet
docker compose up -d --build --wait
docker compose ps
```

This starts every Hades service but does not manage the standalone Traefik container.

The backend dependencies live in the named `devboard-backend-vendor` volume. Only `app` installs them; worker and scheduler start after `app` is healthy and mount the same volume. Do not remove this volume during an ordinary deployment.

## Preflight

Before deployment, verify the external infrastructure without modifying it:

```bash
docker inspect traefik --format '{{.State.Status}}'
docker network inspect traefik_default --format '{{.Name}}'
test -s /home/ubuntu/acme.json
docker compose config --quiet
```

If the container or network is missing, stop. Follow `/home/ubuntu/traefik-readme.md`; do not patch around the problem by adding Traefik to the Hades Compose project.

## Routing contract

- HTTP `Host(home-sweet-home.cloud)` redirects to HTTPS.
- HTTPS frontend catch-all targets container service `frontend` on port 80.
- `/api`, `/sanctum`, and `/storage` target `app` on port 8000 and use Basic Auth.
- `/api/hades/v1` and `/api/plugin/v1` target `app` with their explicitly documented authentication contracts.
- `/install.sh` and `/install.ps1` target the frontend container without the dashboard Basic Auth middleware.
- PostgreSQL and Neo4j never receive public Traefik routers.

Router priorities in `docker-compose.devboard.traefik.yaml` are intentional: exact installer paths outrank API prefixes, and API prefixes outrank the frontend catch-all.

## Safe changes

For Hades-only routing changes:

1. Edit `docker-compose.devboard.traefik.yaml`.
2. Run `scripts/test_hades_compose_contract.sh`.
3. Run `docker compose config --quiet` without printing resolved secret labels.
4. Apply only the affected Hades services with the canonical `docker compose up` command.
5. Inspect router labels and test public HTTP/HTTPS paths.

Do not use `docker compose down --volumes`. It is unnecessary for routing changes and would remove Hades data volumes. A normal Hades `docker compose down` does not own Traefik, but it temporarily removes Hades routes because the labeled containers stop.

## Diagnosis

Show Hades containers and networks:

```bash
docker compose ps
docker inspect devboard-app-1 devboard-frontend-1 \
  --format '{{.Name}} {{range $name, $network := .NetworkSettings.Networks}}{{$name}} {{end}}'
```

Confirm the active frontend artifact:

```bash
docker exec devboard-frontend-1 sh -lc "grep -o '<title>[^<]*' /usr/share/nginx/html/index.html"
```

Confirm backend health from inside its container:

```bash
docker exec devboard-app-1 php -r \
  'exit(@file_get_contents("http://127.0.0.1:8000/api/hades/v1/health") === false ? 1 : 0);'
```

For Traefik logs, shared network diagnosis, certificate recovery, or container reconstruction, use the host-level runbook.
