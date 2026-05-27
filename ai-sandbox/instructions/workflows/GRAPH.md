# GRAPH.md

Use when refreshing AST, graph, sidecar, tree, or Neo4j artifacts.

## Required Reads

- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/graphify/RUNBOOK.md`
- `ai-sandbox/instructions/graphify/NEO4J.md`

## Allowed Writes

- `ai-sandbox/graph/**`
- `graphify-out/**` when Graphify requires its default output directory
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`

## Commands

```bash
python3 ai-sandbox/scripts/refresh_graph.py
python3 ai-sandbox/scripts/audit_sandbox.py
```

## Completion

- Graph audit passes or failures are logged.
- Graph sources stay inside configured project root.
- Neo4j load status is recorded when Neo4j is required.
