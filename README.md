# AI Sandbox Framework

Portable Codex-native framework for onboarding new or existing projects into an agentic development workflow.

## DevBoard V1

This repository is being migrated into DevBoard: a self-hosted Laravel + Inertia React dashboard with a local Python plugin and analyzer for onboarding repositories through Genesis Import.

Primary modules:

```text
backend/   Laravel API, dashboard, artifact registry, wiki, graph import
plugin/    Python CLI/local plugin used by Codex or Claude-facing adapters
analyzer/  Python code analyzer that generates Genesis artifacts
```

Docker setup:

```bash
docker info --format '{{.OSType}}/{{.Architecture}}'
docker compose -f docker-compose.devboard.yaml up app node postgres neo4j
```

If local ports are already occupied, override only the host bindings:

```bash
DEVBOARD_APP_PORT=18000 \
DEVBOARD_VITE_PORT=15173 \
DEVBOARD_POSTGRES_PORT=15432 \
DEVBOARD_NEO4J_HTTP_PORT=17474 \
DEVBOARD_NEO4J_BOLT_PORT=17687 \
docker compose -f docker-compose.devboard.yaml up app node postgres neo4j
```

The base compose file is multi-arch and should be used for local development. On a Mac M4, do not infer the deployment platform from the host CPU; Docker Desktop reports the actual container platform. For Ubuntu server x64 validation, add the amd64 override:

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml up app node postgres neo4j
```

The `node` service keeps `backend/node_modules` inside a Docker volume. This prevents Linux native npm bindings from overwriting the host checkout when local Mac builds and Docker builds are both used.

Initialize the backend inside the app container:

```bash
docker compose -f docker-compose.devboard.yaml exec app php artisan migrate --seed --seeder=DevBoardSeeder
docker compose -f docker-compose.devboard.yaml exec app php artisan test
```

Rebuild the Neo4j projection from stored validated graph artifacts:

```bash
docker compose -f docker-compose.devboard.yaml exec app php artisan devboard:neo4j-rebuild
docker compose -f docker-compose.devboard.yaml exec app php artisan devboard:neo4j-rebuild --snapshot=<snapshot_id>
docker compose -f docker-compose.devboard.yaml exec app php artisan devboard:neo4j-rebuild --mode=fake
```

Purge retained artifact contents after the configured window while preserving DB metadata and audit logs:

```bash
docker compose -f docker-compose.devboard.yaml exec app php artisan devboard:artifacts-retain --dry-run
docker compose -f docker-compose.devboard.yaml exec app php artisan devboard:artifacts-retain --days=90
```

Python plugin/analyzer setup:

```bash
python3 -m venv /tmp/devboard-plugin-venv
/tmp/devboard-plugin-venv/bin/python -m pip install -e analyzer -e plugin pytest
/tmp/devboard-plugin-venv/bin/devboard version
```

Delta Sync after Genesis:

```bash
/tmp/devboard-plugin-venv/bin/devboard delta run \
  --project-id <project_id> \
  --repository-id <repository_id> \
  --local-workspace-id <local_workspace_id> \
  --base-snapshot-id <snapshot_id>
```

`delta run` starts a `delta_sync` run when needed, builds a local Delta bundle under `.devboard/artifacts/delta/`, uploads artifacts by chunks, finalizes the sync, and stores the new snapshot id in `.devboard/state.json`.

Codex/Claude MCP setup:

```bash
/tmp/devboard-plugin-venv/bin/devboard-mcp
```

The versioned Codex plugin source is under `plugin/`. It includes `plugin/.codex-plugin/plugin.json` and `plugin/.mcp.json`, which expose the `devboard` MCP server through the `devboard-mcp` entrypoint. MCP tools use credentials from `~/.config/devboard/credentials.json` or `DEVBOARD_CREDENTIALS_PATH`; they do not accept raw backend tokens as tool parameters.

PostgreSQL and Neo4j are required for the target self-hosted runtime. The automated onboarding Genesis E2E is a fast isolated smoke test: it uses temporary SQLite storage and fake graph-import acceptance so it can run without mutating persistent Docker volumes.

```bash
python3 -m venv /tmp/devboard-e2e-venv
/tmp/devboard-e2e-venv/bin/python -m pip install pytest
/tmp/devboard-e2e-venv/bin/python -m pytest tests/e2e/test_onboarding_genesis.py -q
```

The bootstrap script can also be run directly:

```bash
scripts/devboard_e2e_bootstrap.sh
```

It creates a temporary Laravel database, seeds Admin/project/repository/token state, starts the backend, registers a plugin device, links a fixture repository, builds and uploads Genesis artifacts, validates `.devboard/state.json` has no token, checks the dashboard project endpoint, and writes a JSON report.

See `docker/devboard/README.md` for service ports and platform notes.

## Workspace Shape

```text
workspace/
  AGENTS.md
  ai-sandbox/
  project/
```

Open Codex at `workspace/`, not inside `project/`.

## First Run

1. Copy `AGENTS.md` and `ai-sandbox/` into the client workspace.
2. Copy `docker-compose.graph.yaml` if Neo4j is not already provided by the workspace.
3. Put or clone the client project under `project/`.
4. Open Codex at the workspace root.
5. Codex must read `ai-sandbox/INIT.md`.
6. Codex must interview the developer before modifying project files.

## Standard Commands

```bash
python3 ai-sandbox/scripts/detect_environment.py
python3 ai-sandbox/scripts/bootstrap_dependencies.py
python3 ai-sandbox/scripts/init_sandbox.py
python3 ai-sandbox/scripts/discover_project.py
python3 ai-sandbox/scripts/generate_wiki_seed.py
python3 ai-sandbox/scripts/refresh_graph.py
python3 ai-sandbox/scripts/audit_sandbox.py
```

## Neo4j

Neo4j is part of the standard suite, not an optional add-on.

```bash
docker compose -f docker-compose.graph.yaml up -d neo4j
python3 ai-sandbox/scripts/neo4j_export.py
```

Browser:

```text
http://localhost:7474
```

Credentials:

```text
neo4j / graphify-sandbox
```

## Vendored Tools

Graphify is cached under `ai-sandbox/vendor/python/wheels/`. Docker image archives, when available for the detected Docker platform, belong under `ai-sandbox/vendor/docker/images/<docker-os>-<docker-arch>/`.
