# AI DevBoard Runtime Sequences

This document defines V1 runtime sequences for plugin, backend, dashboard, artifact, graph, and wiki behavior.

## Actors

```text
Admin
PM
Developer
Codex/Claude/local model
Python plugin CLI/MCP
Laravel backend
PostgreSQL
Filesystem artifact storage
Neo4j
```

## Sequence 1 - Plugin Token Onboarding

```mermaid
sequenceDiagram
    participant Admin
    participant Dashboard
    participant Backend
    participant PostgreSQL
    participant Plugin

    Admin->>Dashboard: Create plugin token
    Dashboard->>Backend: POST token create
    Backend->>PostgreSQL: Store token hash and scopes
    Backend-->>Dashboard: Show devb_live_<id>|<secret> once
    Admin->>Plugin: Paste token locally
    Plugin->>Backend: POST /api/plugin/v1/auth/check
    Backend->>PostgreSQL: Validate token hash, scopes, expiry
    Backend-->>Plugin: Token valid, device registration required
    Plugin->>Backend: POST /api/plugin/v1/devices/register
    Backend->>PostgreSQL: Store device and bind token
    Backend-->>Plugin: device_id active
    Plugin->>Plugin: Store credentials outside repository
```

Failure rules:

- invalid token returns `unauthorized`;
- revoked token returns `token_revoked`;
- missing scope returns `scope_missing`;
- token secret is never returned after creation.

## Sequence 2 - Repository Link and Context Pull

```mermaid
sequenceDiagram
    participant Developer
    participant Plugin
    participant Backend
    participant PostgreSQL
    participant LocalRepo

    Developer->>Plugin: devboard repos link
    Plugin->>Backend: GET /projects
    Backend->>PostgreSQL: Load permitted projects
    Backend-->>Plugin: Project list
    Plugin->>Backend: GET /projects/{project}/repositories
    Backend-->>Plugin: Repository list
    Plugin->>LocalRepo: Read local root, branch, head, dirty status
    Plugin->>Backend: POST /repositories/{repo}/local-workspaces
    Backend->>PostgreSQL: Store local workspace
    Backend-->>Plugin: local_workspace_id
    Plugin->>Backend: GET /repositories/{repo}/policy
    Backend-->>Plugin: RepositoryPolicy
    Plugin->>Backend: GET /repositories/{repo}/instructions
    Backend-->>Plugin: Context pack
    Plugin->>LocalRepo: Write .devboard/AGENTS.generated.md
```

Failure rules:

- plugin must not write `.devboard/AGENTS.generated.md` before policy/context succeeds;
- generated context excludes tokens and secrets;
- local workspace state is not remote Git truth.

## Sequence 3 - Genesis Import Happy Path

```mermaid
sequenceDiagram
    participant Developer
    participant Plugin
    participant LocalRepo
    participant Backend
    participant PostgreSQL
    participant Storage
    participant Neo4j
    participant Dashboard

    Developer->>Plugin: devboard genesis run
    Plugin->>Backend: POST /runs
    Backend->>PostgreSQL: Create run
    Backend-->>Plugin: run_id
    Plugin->>LocalRepo: Scan files and Git state
    Plugin->>Plugin: Run safety checks
    Plugin->>Plugin: Run tree-sitter/Graphify analyzer
    Plugin->>Plugin: Build Genesis bundle
    Plugin->>Backend: POST /repositories/{repo}/genesis-imports
    Backend->>PostgreSQL: Create GenesisImport and artifact rows
    Backend-->>Plugin: import_id and artifact ids
    loop each artifact chunk
        Plugin->>Backend: PUT chunk
        Backend->>Storage: Store chunk
        Backend->>PostgreSQL: Mark chunk received
    end
    Plugin->>Backend: POST finalize
    Backend->>Storage: Assemble and hash artifacts
    Backend->>PostgreSQL: Validate manifest and create snapshot
    Backend->>Neo4j: Import graph snapshot
    Backend->>PostgreSQL: Write wiki revisions and audit logs
    Backend-->>Plugin: import active
    Plugin->>Backend: POST /runs/{run}/finish
    Dashboard->>Backend: Load project and run detail
    Backend-->>Dashboard: Repository initialized
```

Promotion rules:

- previous snapshot is superseded only after full validation and graph import;
- failed import preserves previous active state;
- dashboard labels the snapshot `local_plugin_snapshot`.

## Sequence 4 - Secret Block During Genesis

```mermaid
sequenceDiagram
    participant Plugin
    participant LocalRepo
    participant Backend
    participant PostgreSQL
    participant Dashboard

    Plugin->>LocalRepo: Scan repository
    Plugin->>Plugin: Detect .env or private key
    Plugin->>Plugin: Exclude blocked content
    Plugin->>Backend: POST run event security.blocked_upload
    Backend->>PostgreSQL: Append run event and audit log
    Plugin->>Backend: POST finalize
    Backend-->>Plugin: secret_scan_blocked
    Backend->>PostgreSQL: Mark GenesisImport failed
    Dashboard->>Backend: Load run detail
    Backend-->>Dashboard: Failed import with blocked safety result
```

Rules:

- hard-blocked content is never uploaded;
- finalize fails when completeness is compromised;
- run detail shows blocked category and evidence without exposing secret content.

## Sequence 5 - Delta Sync

```mermaid
sequenceDiagram
    participant Developer
    participant Plugin
    participant LocalRepo
    participant Backend
    participant PostgreSQL
    participant Storage
    participant Neo4j

    Developer->>Plugin: devboard delta run
    Plugin->>Backend: POST /runs
    Backend-->>Plugin: run_id
    Plugin->>Backend: Get active snapshot
    Backend-->>Plugin: base_snapshot_id
    Plugin->>LocalRepo: Compute changed files
    Plugin->>Plugin: Reparse affected files
    Plugin->>Plugin: Build DeltaPayload and artifacts
    Plugin->>Backend: POST /runs/{run}/delta-syncs
    Backend->>PostgreSQL: Create DeltaSync
    loop each artifact chunk
        Plugin->>Backend: PUT chunk
        Backend->>Storage: Store chunk
    end
    Plugin->>Backend: POST finalize
    Backend->>PostgreSQL: Validate and create new snapshot
    Backend->>Neo4j: Upsert affected graph
    Backend->>PostgreSQL: Update wiki revisions or stale markers
    Backend-->>Plugin: delta imported
    Plugin->>Backend: POST /runs/{run}/finish
```

Rules:

- Delta Sync requires a base snapshot;
- historical snapshots are immutable;
- graph changes are linked to `delta_sync_id`;
- V1 does not mark branch pushed, PR opened, or merged.

## Sequence 6 - Direct Wiki Write From Plugin

```mermaid
sequenceDiagram
    participant Plugin
    participant Backend
    participant PostgreSQL
    participant Dashboard

    Plugin->>Backend: POST /runs/{run}/wiki/revisions
    Backend->>Backend: Validate source_status and evidence_refs
    Backend->>PostgreSQL: Append WikiRevision
    Backend->>PostgreSQL: Update WikiPage current_revision_id
    Backend->>PostgreSQL: Append audit log wiki.updated
    Dashboard->>Backend: Load wiki page
    Backend-->>Dashboard: Content with source banner
```

Validation rules:

- `verified_from_code` requires evidence refs;
- missing evidence can be accepted only as `needs_verification`;
- old revisions remain queryable;
- conflicts are represented by source status, not by deleting content.

## Sequence 7 - Dashboard PM Flow

```mermaid
sequenceDiagram
    participant PM
    participant Dashboard
    participant Backend
    participant PostgreSQL

    PM->>Dashboard: Open home
    Dashboard->>Backend: GET Kanban/project summary
    Backend->>PostgreSQL: Load tasks, runs, risk, wiki status
    Backend-->>Dashboard: Kanban PM-first data
    PM->>Dashboard: Open blocked task
    Dashboard->>Backend: GET task detail
    Backend-->>Dashboard: Task with linked run and repository
    PM->>Dashboard: Open run detail
    Dashboard->>Backend: GET run detail
    Backend-->>Dashboard: Timeline, artifacts, risk, source labels
```

Permission rules:

- PM can edit tasks and business wiki;
- PM cannot create plugin tokens;
- PM cannot run code-write commands;
- PM can inspect artifacts only through permitted dashboard views.

## Run State Machine

```mermaid
stateDiagram-v2
    [*] --> created
    created --> started
    started --> context_pulled
    context_pulled --> local_snapshot_received
    local_snapshot_received --> working
    working --> heartbeat
    heartbeat --> working
    working --> artifact_uploaded
    artifact_uploaded --> working
    working --> finished
    working --> failed
    working --> aborted
    finished --> reported
    failed --> reported
    aborted --> reported
    reported --> [*]
```

Invalid transitions:

```text
finished -> working
failed -> working
aborted -> working
reported -> artifact_uploaded
```

## Genesis Import State Machine

```mermaid
stateDiagram-v2
    [*] --> started
    started --> uploading
    uploading --> validating
    validating --> importing
    importing --> active
    started --> aborted
    uploading --> failed
    validating --> failed
    importing --> failed
    active --> [*]
    failed --> [*]
    aborted --> [*]
```

Promotion to `active` requires:

```text
all required artifacts validated
snapshot created
Neo4j import completed
wiki revisions handled
audit log written
```

## Delta Sync State Machine

```mermaid
stateDiagram-v2
    [*] --> started
    started --> uploading
    uploading --> validating
    validating --> imported
    started --> aborted
    uploading --> failed
    validating --> failed
    imported --> [*]
    failed --> [*]
    aborted --> [*]
```

Delta failure leaves the previous active snapshot unchanged.

## Background Jobs

Required V1 jobs:

```text
ValidateArtifactChunks
FinalizeGenesisImport
ImportGenesisGraphToNeo4j
WriteGenesisWikiRevisions
FinalizeDeltaSync
ImportDeltaGraphToNeo4j
MarkStaleWikiPages
DetectMissedRunHeartbeat
```

Retry rules:

- chunk validation can retry safely;
- graph import can retry from validated artifacts;
- wiki revision writes can retry idempotently by run id and page slug;
- failed finalize must not promote partial state.

## Operational Acceptance

Runtime behavior is accepted when:

- every mutating plugin call creates a run event or audit log;
- failed uploads do not corrupt active snapshots;
- Neo4j can be rebuilt from validated artifacts;
- dashboard can explain why a run failed;
- source status is visible wherever technical knowledge appears;
- backend never requires direct source code access in V1.

