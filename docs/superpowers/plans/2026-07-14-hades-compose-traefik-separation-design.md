# Hades Compose and Traefik Separation Design

## Goal

Make the complete Hades application stack start with one predictable command while keeping the server-wide Traefik process outside the Hades Compose lifecycle.

## Ownership boundary

- Hades owns `app`, `frontend`, `worker`, `scheduler`, `postgres`, and `neo4j`.
- The host owns the standalone `traefik` container, its ACME state, ports, and shared Docker network.
- Hades owns only its Docker labels and its attachment to the external `traefik_default` network.
- `docker compose down` in the Hades repository must never stop or remove Traefik.

## Canonical command

The server `.env` sets `COMPOSE_FILE` to the base Hades file plus the Traefik integration override. Operators can therefore run:

```bash
docker compose up -d --build --wait
```

The command starts every Hades service. It requires the external `traefik_default` network and standalone `traefik` container to exist already.

## Startup ordering

Only `app` runs `composer install`, writing dependencies into the shared `devboard-backend-vendor` Docker volume instead of the Git checkout. Once its HTTP health endpoint succeeds, Docker may start the frontend, worker, and scheduler. This prevents concurrent Composer writes and keeps runtime-generated autoload metadata out of the worktree. PostgreSQL and Neo4j retain their existing health gates and named volumes.

## Documentation

- `~/traefik-readme.md` is the host-level recovery and inspection runbook.
- `docs/runbooks/traefik-integration.md` documents the repository side of the boundary.
- `AGENTS.md` points context-free agents to the runbook and states the non-negotiable ownership rules.
- The root README uses current React frontend service names and the canonical server command.

## Safety

- Existing Compose project name and named volumes remain unchanged.
- No database reset, volume removal, or seed operation is part of this work.
- Secrets stay in the ignored server `.env`; documentation contains variable names only.
- Validation covers the rendered Compose model, container health, data counts, and public routing.
