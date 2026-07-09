# Memory Graph Reconciliation

## Purpose

`developer_provided`: DevBoard currently has two parallel graph memory planes.

1. The **Genesis/Plugin graph** imported into Neo4j via `graph_snapshot` artifacts and Cypher MERGE commands.
2. The **Hades artifact graph** stored as raw JSON in `hades_agent_artifacts` and traversed in-memory by `GraphTraversalController`.

This document defines the canonical source of truth, the relationship between the two planes, and the reconciliation path going forward.

## Source Status

- `verified_from_code`: all references below confirmed by reading `backend/app/Services/GenesisGraphImportService.php`, `backend/app/Jobs/ImportGraphToNeo4j.php`, `backend/app/Http/Controllers/Hades/GraphTraversalController.php`, `backend/app/Assistants/Tools/QueryProjectGraphTool.php`, `backend/app/Services/Neo4jRebuildService.php`, `backend/app/Services/Hades/HadesProjectAwareness.php`, `backend/routes/api.php`, and `docs/ai-devboard/05_GENESIS_IMPORT.md`.

## Current State

### Genesis/Plugin Graph Path

- Protocol: `POST /api/plugin/v1/genesis-imports/{id}/finalize` (Genesis) / `POST /api/plugin/v1/delta-syncs/{id}/finalize` (Delta).
- Artifact type: `graph_snapshot` stored in `artifacts` table with actual JSON on filesystem.
- Import job: `ImportGenesisGraphToNeo4j` (genesis) / `ImportGraphToNeo4j` (delta) dispatch `GenesisGraphImportService::importGraphArtifact()`.
- Neo4j model: `CodeNode` nodes (label per `node.labels`, keyed by `external_id + snapshot_id`), `DevBoardSnapshot` metadata node, `RELATED` edges (keyed by `external_id + snapshot_id`).
- Rebuild: `Neo4jRebuildService` reads `artifacts` table, finds all `graph_snapshot` rows, re-imports into Neo4j by snapshot id.
- Server-side agent tool: `QueryProjectGraphTool` reads the latest `graph_snapshot` artifact JSON directly from filesystem storage (not Neo4j), does in-memory search/bounding.

### Hades Graph Path

- Protocol: `POST /api/hades/v1/artifacts` (upload) with schema `hades.code_graph.v1` or `hades.php_graph.v1`.
- Storage: `hades_agent_artifacts` table stores raw JSON payload in `artifact` column.
- Traversal: `GET /api/hades/v1/graph/traverse` uses `GraphTraversalController`. Loads the latest `hades_agent_artifacts` row for the workspace binding, decodes the JSON, builds an in-memory node/edge map from `routes` + `symbols` + `edges` fields, runs BFS traversal.
- Coverage: `HadesProjectAwareness.codeGraphCoverage()` checks whether `hades.code_graph.v1` / `hades.php_graph.v1` artifacts exist and are current.
- Neo4j involvement: **none**. The Hades graph path has never used Neo4j.

### Relationship Between The Two Planes

| Aspect | Genesis/Plugin Graph | Hades Graph |
|--------|---------------------|-------------|
| Ingest path | Plugin → backend finalize → Neo4j import job | Hades agent → `POST /api/hades/v1/artifacts` |
| Storage | `graph_snapshot` artifact (filesystem + `artifacts` table) + Neo4j | `hades_agent_artifacts` row (JSON in column) |
| Query surface | `QueryProjectGraphTool` (reads JSON artifact, not Neo4j) | `GET /api/hades/v1/graph/traverse` (reads JSON artifact) |
| Graph model | `CodeNode` + `RELATED` in Neo4j | `routes` + `symbols` + `edges` in JSON |
| Rebuildability | Yes (`Neo4jRebuildService`) | No (single artifact per binding, versioned by `created_at`) |

The two planes operate independently. They share no code, no schema normalization, and no query unification.

## Decision

### Canonical Structural Graph

The **canonical structural graph** is Neo4j, rebuilt from Postgres (`snapshots` + `artifacts` tables) and filesystem artifact storage.

This choice is based on:
- Neo4j is already a required service in V1 (`03_DOMAIN_MODEL.md`, line 20).
- The Genesis graph import already produces a Cypher-backed `CodeNode` + `RELATED` model with indexes, snapshot scoping, and batch operations.
- `Neo4jRebuildService` already supports complete rebuild from stored artifacts.
- The existing `Neo4jClient` interface (`App\Services\Neo4j\Neo4jClient.php`) provides a typed, testable boundary (faked in tests via `FakeNeo4jClient`).
- The Laudis Neo4j PHP client is already a declared Composer dependency.

### Hades Graph Reads

Hades graph reads **must query the canonical Neo4j graph** for new features. Do not maintain a parallel artifact-only graph in `hades_agent_artifacts` for new Hades capabilities.

Rationale:
- Having two code graphs with different schemas and no shared normalization leads to conflicting answers.
- The `GraphTraversalController` currently traverses a completely separate JSON graph with different node/edge shapes — this is technical debt.
- Future Hades graph queries (e.g., symbol lookup, dependency path, impact analysis) should use the Neo4j `CodeNode` + `RELATED` model.

### Artifact JSON Graph

The JSON graph artifact (`graph-snapshot.json`) stored on the filesystem remains as:
- **Import/rebuild input** for Neo4j.
- **Compatibility fallback** for `QueryProjectGraphTool` until that tool is migrated to Neo4j queries.

It is NOT the canonical source of truth. It is the transport format that feeds the canonical store.

### Search Index

Any future search index (e.g., full-text search on node names, symbol search, route lookup) is a **derived projection** of the canonical Neo4j graph. It must be rebuildable from Neo4j and must not become a parallel source of truth.

## Migration Rules

### Existing Hades Endpoints: Keep Compatibility

The following existing Hades endpoints are **frozen for compatibility**. They continue to read from `hades_agent_artifacts` until a later migration task replaces them with Neo4j-backed implementations:

| Endpoint | Method | Status |
|----------|--------|--------|
| `/api/hades/v1/graph/traverse` | GET | **Keep compat** — reads `hades_agent_artifacts` JSON. Freeze schema; do not extend. |
| `/api/hades/v1/artifacts` (upload) | POST | **Keep compat** — writes `hades_agent_artifacts` with schemas `hades.code_graph.v1`, `hades.php_graph.v1`. Continue accepting but route new uploads to Neo4j import when graph schema matches. |
| `/api/hades/v1/project-awareness/status` | GET | **Keep compat** — `codeGraphCoverage()` reads `hades_agent_artifacts`. Add Neo4j-derived coverage check alongside. |

### New Hades Endpoints: Must Query Neo4j

Any new Hades endpoint that provides graph data (`/api/hades/v1/graph/*`) must query the canonical Neo4j graph via `Neo4jClient`, not `hades_agent_artifacts` JSON.

| Endpoint pattern | Requirement |
|-----------------|-------------|
| `GET /api/hades/v1/graph/symbols` (future) | Query Neo4j `CodeNode` by label and snapshot scope. |
| `GET /api/hades/v1/graph/dependencies` (future) | Query Neo4j `RELATED` edges by type and direction. |
| `GET /api/hades/v1/graph/impact` (future) | Traverse Neo4j graph with depth-limited BFS. |
| Any graph traversal extension | Use `Neo4jClient` + Cypher, not JSON in-memory traversal. |

### New Genesis/Plugin Features

- `QueryProjectGraphTool`: keep as-is (reads JSON from filesystem) until a migration task switches it to Neo4j Cypher queries. This is a lower-priority migration because the tool already uses bounded preview semantics and is read-only.
- Any new server-side agent tool that queries the graph must use `Neo4jClient`.

## Reconciliation Path (Future Tasks)

1. **Normalize Hades graph schemas to match Neo4j `CodeNode`+`RELATED` model.** Create a mapper from `hades.code_graph.v1` / `hades.php_graph.v1` JSON shape to `CodeNode` Cypher commands.
2. **Route Hades graph uploads into Neo4j import.** When a Hades agent uploads a `hades.code_graph.v1` artifact, import it into Neo4j via the existing `GenesisGraphImportService` batch commands (or a shared import service).
3. **Replace `GraphTraversalController` with Neo4j-backed traversal.** Instead of in-memory BFS on decoded JSON, emit Cypher queries against the canonical `CodeNode` graph.
4. **Migrate `QueryProjectGraphTool` to Neo4j.** Replace filesystem JSON read with Cypher queries against `CodeNode` nodes.
5. **Deprecate `hades_agent_artifacts` as a graph store.** Once all graph consumers read from Neo4j, `hades_agent_artifacts` rows with graph schemas become import-only staging records.

## Verification

- `rg -n "UNRESOLVED|FILL_ME|NOT_DECIDED" docs/ai-devboard/13_MEMORY_GRAPH_RECONCILIATION.md`: expected no matches.
