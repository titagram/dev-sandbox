# AI Sandbox Framework

Portable Codex-native framework for onboarding new or existing projects into an agentic development workflow.

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
