# AGENTS.md

Mandatory entrypoint for Codex and other LLMs working in this workspace.

## First Rule

Read `ai-sandbox/INIT.md` before modifying files.

## Hard Boundaries

- Do not modify `project/` during sandbox initialization unless the developer explicitly asks for a project code change.
- Do not generate wiki, graph, or architectural claims before `ai-sandbox/config/project.yaml` is initialized.
- Do not treat inferred facts as verified.

## Server Traefik Boundary

- On `ubuntu@[IP_ADDRESS]`, Traefik is shared host infrastructure and is **not** owned by this repository's Compose project.
- Never add a `traefik` service to a Hades Compose file and never recreate/remove the standalone `traefik` container as part of a Hades deployment.
- Hades exposes routes through `docker-compose.devboard.traefik.yaml` and the external Docker network `traefik_default`.
- The server `.env` selects the base plus routing override through `COMPOSE_FILE`; the canonical deploy command is `docker compose up -d --build --wait` from `/home/ubuntu/dev-sandbox`.
- Before changing labels, routers, middleware, TLS, ports, or the external network, read `docs/runbooks/traefik-integration.md` completely.
- Host-level Traefik reconstruction and recovery details are in `/home/ubuntu/traefik-readme.md` and deliberately live outside this repository.

## Task Routing

Use `ai-sandbox/instructions/INDEX.md` to classify the task and follow the matching workflow.

## Logbooks

- Sandbox/tooling work: `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`
- Project work: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
