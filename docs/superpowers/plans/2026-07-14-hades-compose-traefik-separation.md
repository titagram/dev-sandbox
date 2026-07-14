# Hades Compose and Traefik Separation Implementation Plan

## Task 1: Lock the Compose contract

- Add a shell contract test for the merged base and Traefik integration Compose files.
- Assert all Hades services exist and no Traefik service is owned by the project.
- Assert the external network and router labels are present.
- Assert app health gates frontend, worker, and scheduler.

## Task 2: Make startup deterministic

- Add backend and frontend healthchecks.
- Remove duplicate `composer install` commands from worker and scheduler.
- Store backend runtime dependencies in a shared named vendor volume rather than the bind-mounted Git checkout.
- Gate frontend, worker, and scheduler on a healthy app.

## Task 3: Configure the server command

- Persist `COMPOSE_FILE`, project name, host, network, resolver, and existing Basic Auth value in the ignored server `.env`.
- Keep secret values out of command output and Git.
- Verify `docker compose config` works without explicit `-f` arguments.

## Task 4: Write durable operations documentation

- Add the host Traefik runbook at `~/traefik-readme.md`.
- Add the repository integration runbook.
- Update `AGENTS.md` and the root README.

## Task 5: Deploy and verify

- Record pre-deploy PostgreSQL and Neo4j counts.
- Run the canonical one-command deployment.
- Require all Hades services to be running and healthy.
- Verify counts are unchanged and public routes reach the expected frontend/backend.
- Review the final Git diff without touching pre-existing generated files.
