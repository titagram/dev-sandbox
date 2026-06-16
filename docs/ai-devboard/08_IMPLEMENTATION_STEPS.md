# AI DevBoard Implementation Steps

This document defines the V1 implementation path after the specification package is accepted.

The first implementation target is not a tiny MVP. It is a working vertical slice for onboarding and Genesis Import.

## Repository Strategy

Use a monorepo modular layout:

```text
backend/
  laravel app, API, auth, domain, jobs
dashboard/
  Inertia React pages and components inside the Laravel frontend boundary
plugin/
  Python CLI, MCP server, local analyzer orchestration
analyzer/
  reusable Python analysis modules migrated from ai-sandbox/scripts and adapters
docs/ai-devboard/
  architecture and implementation specs
```

The existing `ai-sandbox` remains as bootstrap reference during transition. It is not the long-term runtime product.

Migration style:

```text
big bang product architecture
selective reuse of Python analyzer logic
compat bootstrap through AGENTS.md and ai-sandbox/INIT.md during transition
```

## Phase 0 - Specification Package

Deliver:

```text
03_DOMAIN_MODEL.md
04_PLUGIN_SERVER_CONTRACT.md
05_GENESIS_IMPORT.md
06_DELTA_SYNC.md
07_SECURITY_MODEL.md
08_IMPLEMENTATION_STEPS.md
09_DASHBOARD_WIREFRAMES.md
10_RUNTIME_SEQUENCES.md
```

Acceptance:

- docs cover domain, plugin contract, Genesis, Delta, security, implementation, wireframes, and runtime sequences;
- docs are internally consistent on V1 decisions;
- no application code is started before review.

## Phase 1 - Backend Skeleton

Build Laravel app with:

```text
PostgreSQL connection
filesystem artifact disk
Neo4j service connection
dashboard auth
plugin API route group /api/plugin/v1
basic audit logging
```

Create initial tables for:

```text
users
roles
permissions
api_tokens
devices
projects
repositories
local_workspaces
tasks
kanban_boards
kanban_columns
runs
run_events
artifacts
snapshots
genesis_imports
wiki_pages
wiki_revisions
audit_logs
```

Acceptance:

- migrations run on fresh PostgreSQL database;
- seed creates Admin, role defaults, one demo project, default board columns;
- dashboard login works;
- plugin route group rejects unauthenticated requests;
- audit log writes on token creation and denied plugin request.

## Phase 2 - Plugin Auth and Repository Link

Build Python plugin core with CLI and MCP wrappers.

Required commands:

```text
devboard auth check
devboard auth register-device
devboard projects list
devboard repos link
devboard repos policy
devboard context pull
```

Backend endpoints:

```text
POST /api/plugin/v1/auth/check
POST /api/plugin/v1/devices/register
GET  /api/plugin/v1/projects
GET  /api/plugin/v1/projects/{project_id}/repositories
POST /api/plugin/v1/repositories/{repository_id}/local-workspaces
GET  /api/plugin/v1/repositories/{repository_id}/policy
GET  /api/plugin/v1/repositories/{repository_id}/instructions
```

Acceptance:

- Admin creates a token in dashboard and sees it once;
- plugin stores token outside the repository;
- plugin registers device;
- plugin links local repository;
- `.devboard/state.json` is created without secrets;
- `.devboard/` is added to `.git/info/exclude` when possible.

## Phase 3 - Run Lifecycle

Implement:

```text
run start
heartbeat
event append
finish
fail
abort
```

Backend endpoints:

```text
POST /api/plugin/v1/runs
POST /api/plugin/v1/runs/{run_id}/heartbeat
POST /api/plugin/v1/runs/{run_id}/events
POST /api/plugin/v1/runs/{run_id}/finish
```

Acceptance:

- plugin starts a `genesis_import` run;
- dashboard run detail shows status, actor, device, branch, timestamps, and events;
- missed heartbeat can be represented as warning;
- finished and failed runs are immutable except for appended audit-safe events.

## Phase 4 - Genesis Analyzer Bundle

Migrate useful `ai-sandbox` logic into plugin/analyzer modules:

```text
environment detection
stack detection
file inventory
file hashing
adapter detection
Graphify/tree-sitter orchestration
wiki seed generation concepts
graph audit concepts
```

Implement local Genesis bundle generation:

```text
manifest.json
file-inventory.json
file-hashes.json
dependency-inventory.json
env-inventory.json
symbol-index.json
relation-index.json
graph-snapshot.json
route-index.json
entity-model-index.json
migration-schema-index.json
test-map.json
metrics.json
wiki-pages.json
analysis-quality-report.json
security-report.json
```

Acceptance:

- plugin can generate bundle for a small fixture repository;
- hard-block content prevents unsafe upload;
- warned directories are excluded and recorded;
- graph artifact is valid for backend import;
- wiki pages include source status and evidence refs.

## Phase 5 - Artifact Upload and Genesis Finalize

Implement:

```text
manifest registration
chunk upload
hash validation
finalize
filesystem storage
artifact rows
snapshot row
GenesisImport row
```

Backend endpoints:

```text
POST /api/plugin/v1/repositories/{repository_id}/genesis-imports
PUT  /api/plugin/v1/genesis-imports/{import_id}/artifacts/{artifact_id}/chunks/{chunk_index}
POST /api/plugin/v1/genesis-imports/{import_id}/finalize
GET  /api/plugin/v1/genesis-imports/{import_id}
```

Acceptance:

- upload can resume safely after duplicate chunk send;
- mismatched chunk hash fails;
- missing chunk blocks finalize;
- successful finalize creates active snapshot;
- previous snapshot is superseded only after successful finalize.

## Phase 6 - Neo4j Import

Implement backend graph import job.

Acceptance:

- validated graph artifact imports into Neo4j;
- `DevBoardSnapshot` node is created;
- code nodes and relations include snapshot provenance;
- failed import does not promote the snapshot active;
- dashboard can show graph import status.

## Phase 7 - Wiki Direct Writes

Implement direct plugin/AI wiki revision writes.

Backend endpoint:

```text
POST /api/plugin/v1/runs/{run_id}/wiki/revisions
```

Acceptance:

- plugin writes `verified_from_code` technical pages with evidence;
- missing evidence rejects `verified_from_code`;
- old revisions remain accessible;
- stale/conflict statuses are represented;
- dashboard shows source status on wiki pages.

## Phase 8 - Dashboard V1

Implement Inertia React pages:

```text
Kanban home
Project detail
Run detail
```

Acceptance:

- PM-first home shows tasks, blocked items, owners, risk, and recent runs;
- project detail shows repositories, Genesis status, artifacts, wiki status, and graph status;
- run detail shows events, artifacts, safety results, risk report, and source labels;
- PM cannot reach code-write actions;
- Admin can create and revoke plugin tokens.

## Phase 9 - Delta Sync

Implement after Genesis Import is stable.

Required plugin command:

```text
devboard delta run
```

Backend endpoints:

```text
POST /api/plugin/v1/runs/{run_id}/local-snapshots
POST /api/plugin/v1/runs/{run_id}/delta-syncs
PUT  /api/plugin/v1/delta-syncs/{delta_id}/artifacts/{artifact_id}/chunks/{chunk_index}
POST /api/plugin/v1/delta-syncs/{delta_id}/finalize
```

Acceptance:

- plugin computes changed files against active snapshot;
- affected symbols and relations update;
- new snapshot is created;
- Neo4j graph projection updates;
- wiki pages are updated or marked stale;
- dashboard shows diff summary and risk report.

## Phase 10 - Hardening

Required hardening work:

```text
rate limiting plugin endpoints
artifact retention policy
audit export
token rotation
device revocation
background job retry policy
large artifact stress tests
Neo4j rebuild from stored artifacts
```

Acceptance:

- backend can rebuild Neo4j projection from validated artifacts;
- revoked tokens/devices are denied;
- artifact cleanup is audited;
- large upload tests cover chunk retry and finalize.

## Test Strategy

Backend tests:

```text
auth/token hash validation
scope enforcement
role permission matrix
repository policy enforcement
artifact manifest validation
chunk hash validation
Genesis finalize
Neo4j import job
wiki evidence validation
audit log creation
```

Plugin tests:

```text
credential storage excludes repository
device registration payload
repository link state
file inventory and hashes
secret block/warn scanning
Genesis bundle manifest
chunk upload retry
MCP tools redact tokens
```

End-to-end tests:

```text
token to device to repository link
Genesis bundle to upload to active snapshot
hard-block secret prevents finalize
dashboard shows Kanban/project/run state
Neo4j contains imported snapshot graph
```

## First Slice Completion Definition

The `onboarding + Genesis Import` slice is complete when a developer can:

```text
1. log into dashboard as Admin;
2. create a project and repository;
3. create a plugin token;
4. configure the Python plugin locally;
5. register a device;
6. link a local repo;
7. run Genesis Import;
8. upload artifacts by chunks;
9. finalize import;
10. inspect project detail and run detail in dashboard;
11. query imported graph data through backend-backed UI.
```

