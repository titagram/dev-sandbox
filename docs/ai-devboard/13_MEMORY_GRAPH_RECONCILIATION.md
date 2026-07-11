# Memory Graph Reconciliation

## Purpose

`developer_provided`: DevBoard currently has two parallel graph memory planes.

1. The **Genesis/Plugin graph** imported into Neo4j via `graph_snapshot` artifacts and Cypher MERGE commands.
2. The **Hades artifact graph** stored as raw JSON in `hades_agent_artifacts` and traversed in-memory by `GraphTraversalController`.

This document defines the canonical source of truth, the relationship between the two planes, and the reconciliation path going forward.

## Source Status

- `verified_from_code`: all references below confirmed by reading `backend/app/Services/GenesisGraphImportService.php`, `backend/app/Jobs/ImportGraphToNeo4j.php`, `backend/app/Jobs/ImportGenesisGraphToNeo4j.php`, `backend/app/Services/Graph/GraphQueryService.php`, `backend/app/Http/Controllers/Plugin/GraphQueryController.php`, `backend/app/Http/Controllers/Hades/GraphTraversalController.php`, `backend/app/Assistants/Tools/QueryProjectGraphTool.php`, `backend/app/Services/Neo4jRebuildService.php`, `backend/app/Services/Hades/HadesProjectAwareness.php`, `backend/routes/api.php`, and `docs/ai-devboard/05_GENESIS_IMPORT.md`.

## Current State

### Genesis/Plugin Graph Path

- Protocol: `POST /api/plugin/v1/genesis-imports/{id}/finalize` (Genesis) / `POST /api/plugin/v1/delta-syncs/{id}/finalize` (Delta).
- Artifact type: `graph_snapshot` stored in `artifacts` table with actual JSON on filesystem.
- Import job: `ImportGraphToNeo4j` is the unified implemented job for Genesis and Delta graph imports. `ImportGenesisGraphToNeo4j` remains only as a legacy compatibility wrapper that delegates Genesis work to `ImportGraphToNeo4j('genesis', ...)`.
- Neo4j model: every imported graph node has the base `:CodeNode` label and is keyed by `external_id + snapshot_id`. Known semantic labels are additive: `:File`, `:Function`, `:Class`, and `:Module`; analyzer `Method` labels map to `:Function`. Unknown or unsupported labels remain only `:CodeNode`, while the original input labels are preserved as a node property.
- Neo4j relationships: implemented relationship types are `CALLS`, `DECLARES`, and `IMPORTS`. Unknown or unsupported relationship types are imported as `RELATED` while retaining the original `type` property.
- Rebuild: `Neo4jRebuildService` reads `artifacts` table, finds all `graph_snapshot` rows, re-imports into Neo4j by snapshot id.
- Server-side agent tool: `QueryProjectGraphTool` supports structured graph queries through `GraphQueryService` for `callers`, `callees`, and `path`, scoped to the requested project's latest graph snapshot. It also keeps artifact-JSON text compatibility mode for older text/search-style graph reads against the stored `graph_snapshot` artifact.

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
| Query surface | `GraphQueryService`/`POST /api/plugin/v1/projects/{project}/graph/query` for structured Neo4j reads; artifact-JSON compatibility mode remains for text-style agent queries | `GET /api/hades/v1/graph/traverse` (reads JSON artifact) |
| Graph model | `:CodeNode` base label plus additive `:File`, `:Function`, `:Class`, `:Module`; `CALLS`, `DECLARES`, `IMPORTS`, with `RELATED` fallback | `routes` + `symbols` + `edges` in JSON |
| Rebuildability | Yes (`Neo4jRebuildService`) | No (single artifact per binding, versioned by `created_at`) |

The two planes operate independently. They share no code, no schema normalization, and no query unification.

## Decision

### Canonical Structural Graph

The **canonical structural graph** is Neo4j, rebuilt from Postgres (`snapshots` + `artifacts` tables) and filesystem artifact storage.

This choice is based on:
- Neo4j is already a required service in V1 (`03_DOMAIN_MODEL.md`, line 20).
- The Genesis/Delta graph import already produces a Cypher-backed `:CodeNode` model with additive typed labels, typed relationships, indexes, snapshot scoping, and batch operations.
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
- **Compatibility fallback** for artifact-JSON text mode in `QueryProjectGraphTool`.

It is NOT the canonical source of truth. It is the transport format that feeds the canonical store.

### Search Index

Any future search index (e.g., full-text search on node names, symbol search, route lookup) is a **derived projection** of the canonical Neo4j graph. It must be rebuildable from Neo4j and must not become a parallel source of truth.

`verified_from_code`: `GET /api/hades/v1/memory/search` uses lexical search documents first and pgvector candidates as a secondary retrieval signal when embeddings are operational. The response includes `retrieval.lexical.status`, `retrieval.vector.status`, `retrieval.vector.model`, and `retrieval.vector.candidate_count`; vector status is `disabled`, `ok`, or `degraded`. Individual vector-backed results include bounded `similarity` in `0..1`, `evidence_refs`, and `needs_verification`. Lexical-only behavior remains available when embeddings are disabled or degraded.

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
| `GET /api/hades/v1/graph/dependencies` (future) | Query Neo4j `CALLS`, `DECLARES`, `IMPORTS`, and `RELATED` edges by type and direction. |
| `GET /api/hades/v1/graph/impact` (future) | Traverse Neo4j graph with depth-limited BFS. |
| Any graph traversal extension | Use `Neo4jClient` + Cypher, not JSON in-memory traversal. |

### Genesis/Plugin Query Surface

- `POST /api/plugin/v1/projects/{project}/graph/query` is the implemented plugin graph-query endpoint. It requires a plugin token with `projects.read` scope and accepts structured query types `callers`, `callees`, and `path`.
- `GraphQueryService` resolves the latest snapshot for the supplied `project_id`, runs bounded structured Cypher queries against `:CodeNode` and `:CALLS`, and returns normalized node results. It returns `graph_snapshot_not_found`, `neo4j_unavailable`, or `query_error` rather than falling back silently for structured calls.
- `QueryProjectGraphTool` uses structured Neo4j queries when it receives supported structured parameters. Its older artifact-JSON text compatibility mode remains supported for read-only bounded previews and should not be treated as canonical.
- Any new server-side agent tool that queries the graph must use `Neo4jClient`.

## Schema Mismatch Decisions

The following source-of-truth mismatches are explicit decisions for this remediation:

| Topic | Older or broader requirement | Decision |
| --- | --- | --- |
| Node labels | `05_GENESIS_IMPORT.md` lists many graph-snapshot labels including `Route`, `Controller`, `Model`, `Table`, `Migration`, `Job`, `Event`, `Listener`, `Command`, `Test`, `WikiPage`, `Task`, `Run`, and `Commit`. | `implemented contract`: Neo4j currently imports every node as `:CodeNode` and only adds `:File`, `:Function`, `:Class`, or `:Module`. Broader domain labels are a `future target` and must not be documented as currently queryable labels. |
| Method labels | Analyzer artifacts may emit `Method`. | `implemented contract`: `Method` imports as additive `:Function` for the current schema. |
| Relationship types | `05_GENESIS_IMPORT.md` and relation artifacts include broader relation vocabulary such as `USES`, `MAPS_TO`, `OWNS`, `DOCUMENTS`, `COVERS`, `CREATES_TABLE`, `TOUCHES`, `DEPENDS_ON`, and `MODIFIED`. | `implemented contract`: Neo4j relationship types are `CALLS`, `DECLARES`, `IMPORTS`; every other type imports as `RELATED` with the original type preserved as a property. Broader typed relationship support is a `future target`. |
| Delta affected subgraph | `06_DELTA_SYNC.md` describes affected-subgraph clone/upsert/delete behavior. | `implemented contract`: the backend has commands for snapshot clone, deletion, and upsert using the implemented label/relationship mapping above. More semantic relationship classes remain a `future target`. |
| Query source | Older text said `QueryProjectGraphTool` reads JSON and not Neo4j. | `implemented contract`: structured graph reads use `GraphQueryService` and Neo4j; artifact-JSON text mode remains compatibility only. |

## Reconciliation Path (Future Tasks)

1. **Normalize Hades graph schemas to match the Neo4j implemented contract.** Create a mapper from `hades.code_graph.v1` / `hades.php_graph.v1` JSON shape to `:CodeNode` plus additive typed labels and `CALLS`/`DECLARES`/`IMPORTS`/`RELATED` Cypher commands.
2. **Route Hades graph uploads into Neo4j import.** When a Hades agent uploads a `hades.code_graph.v1` artifact, import it into Neo4j via the existing `GenesisGraphImportService` batch commands (or a shared import service).
3. **Replace `GraphTraversalController` with Neo4j-backed traversal.** Instead of in-memory BFS on decoded JSON, emit Cypher queries against the canonical `CodeNode` graph.
4. **Complete `QueryProjectGraphTool` migration to Neo4j.** Keep existing structured `GraphQueryService` queries and replace the remaining artifact-JSON text compatibility mode with equivalent bounded Neo4j queries when product behavior is ready.
5. **Deprecate `hades_agent_artifacts` as a graph store.** Once all graph consumers read from Neo4j, `hades_agent_artifacts` rows with graph schemas become import-only staging records.

## Verification

- Placeholder-token scan across `docs/ai-devboard`: expected no matches.
