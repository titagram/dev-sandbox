# Codex Handoff - AI DevBoard

This document is the operational handoff for Codex or other AI coding agents working on the DevBoard architecture.

## Required Reading

Before making changes, read:

```text
docs/ai-devboard/00_VISION.md
docs/ai-devboard/01_ARCHITECTURE_DECISIONS.md
```

Treat those files as the current architectural source of truth for this project direction.

## Goal

Transform the current `dev-sandbox` idea into a specification and implementation path for a centralized DevBoard system with a local plugin/connector.

The mature system should replace the current chain of local folders and static files with:

- centralized backend instructions;
- centralized wiki and memory;
- plugin-generated local context;
- plugin-generated code artifacts;
- backend-managed command/model registry;
- Genesis Import;
- Delta Sync;
- role-based dashboard;
- PM Kanban.

## Important Constraint

Do not assume that the backend has direct access to source code.

Default architecture:

```text
plugin reads local code
plugin generates artifacts
plugin uploads artifacts
backend stores and coordinates
Git remains code truth
```

Git server-side integration is optional and must be configurable per repository.

## Do Not Implement Blindly

Before implementing application code, produce a concrete technical plan covering:

1. domain model;
2. plugin-server contract;
3. Genesis Import manifest;
4. Delta Sync payload;
5. artifact types;
6. code exposure policy;
7. command registry;
8. model registry;
9. role/scope matrix;
10. run lifecycle;
11. local generated files policy;
12. test strategy.

## Proposed Logical Implementation Blocks

Do not think in terms of a tiny MVP. Think in compatible logical blocks that can be implemented and tested sequentially.

### Block A - Core Domain

Entities:

- users;
- roles;
- permissions/scopes;
- organizations/workspaces, if needed;
- projects;
- repositories;
- local workspaces/devices;
- API tokens;
- audit logs.

### Block B - Plugin Contract

Define endpoints and payloads for:

- auth/token validation;
- device registration;
- project/repository link;
- policy pull;
- instruction/context pull;
- run start;
- run heartbeat;
- run finish;
- local workspace snapshot;
- artifact upload;
- error reporting.

### Block C - Task and PM Kanban

Entities:

- boards;
- columns;
- tasks;
- labels;
- assignments;
- comments;
- status changes;
- task links to repositories/branches/runs/wiki pages.

The PM dashboard should be Kanban-first.

### Block D - Wiki / Knowledge Base

Entities:

- wiki pages;
- wiki revisions;
- source status;
- evidence metadata;
- manual edits;
- AI proposals;
- verified-from-code sections;
- stale/conflict detection.

### Block E - Genesis Import

Define a bundle/manifest format for first full project analysis.

Expected artifacts:

- manifest;
- file inventory;
- file hashes;
- symbol index;
- relation index;
- route index;
- entity/model index;
- migration/db schema index;
- dependency inventory;
- test map;
- env inventory;
- metrics;
- wiki pages;
- analysis quality report;
- optional graph snapshot;
- optional security findings.

### Block F - Delta Sync

Define incremental update format after code modifications.

Must support:

- branch/base SHA;
- local dirty status;
- changed/staged/unstaged/untracked files;
- diff summary;
- patch depending on policy;
- affected symbols;
- affected graph relations;
- affected wiki pages;
- test output;
- risk report.

### Block G - Command Registry

Command registry must distinguish:

- server-side commands;
- client-side/plugin commands;
- hybrid commands.

Fields to consider:

```text
name
category
description
execution_location
requires_code_access
requires_git_remote
requires_llm
model_tier
writes_code
writes_server_state
output_artifact
allowed_roles
allowed_runtime_profiles
scopes_required
risk_level
requires_approval
input_schema
output_schema
```

### Block H - Model Registry and Escalation

Support configurable model/runtime profiles.

Do not permanently hardcode current local models as read-only. Instead make capabilities configurable by superadmin.

Fields:

- provider;
- model name;
- local/cloud;
- allowed runtime profiles;
- can_write;
- can_review;
- can_plan;
- trust level;
- cost level;
- max context;
- enabled;
- approval requirements.

### Block I - Graph / Code Intelligence

The graph should be built from uploaded artifacts or optional remote read-only Git integration.

Prefer code intelligence entities over raw AST-only storage:

- File;
- Class;
- Method;
- Function;
- Route;
- Controller;
- Model;
- Table;
- Migration;
- Job;
- Event;
- Listener;
- Command;
- Test;
- WikiPage;
- Task;
- Run;
- Commit.

Possible relations:

- MAPS_TO;
- CALLS;
- USES;
- OWNS;
- DOCUMENTS;
- MODIFIED;
- COVERS;
- CREATES_TABLE;
- TOUCHES;
- DEPENDS_ON.

### Block J - Plugin Local Files

The plugin may generate local files, but they must be temporary, ignored, and regenerable.

Preferred local folder:

```text
.devboard/
  AGENTS.generated.md
  cache/
  artifacts/
  state.json
```

Do not commit generated files.

Use `.git/info/exclude` where possible instead of modifying project `.gitignore`.

## Initial Data Model Sketch

Suggested main tables/entities:

```text
users
roles
permissions
api_tokens
projects
repositories
local_workspaces
tasks
kanban_boards
kanban_columns
task_comments
agent_runs
run_events
artifacts
snapshots
genesis_imports
delta_syncs
wiki_pages
wiki_revisions
commands
models
runtime_profiles
escalation_rules
audit_logs
```

## Run Lifecycle

Suggested states:

```text
created
started
context_pulled
local_snapshot_received
working
heartbeat
artifact_uploaded
finished
failed
aborted
reported
branch_pushed
pr_opened
merged
```

The backend must distinguish local plugin state from remote Git state.

## Genesis Import Flow

```text
1. plugin authenticates
2. plugin links local repo to project/repository
3. plugin pulls code exposure policy
4. plugin scans local project
5. plugin runs secret/path safety checks
6. plugin generates artifact bundle
7. plugin starts Genesis Import on server
8. plugin uploads artifacts/chunks
9. server validates hashes
10. server imports artifacts
11. server builds wiki/graph/vector/search indexes if enabled
12. server marks snapshot active
```

## Delta Sync Flow

```text
1. plugin detects local change/diff
2. plugin computes changed files and hashes
3. plugin reparses affected files
4. plugin recomputes affected symbols/relations/metrics
5. plugin identifies affected wiki pages
6. plugin sends delta payload
7. server stores local snapshot
8. server updates project memory and dashboard state
```

## Endpoint Sketch

### Plugin Auth

```text
POST /api/plugin/auth/check
POST /api/plugin/device/register
POST /api/plugin/device/login/start
POST /api/plugin/device/login/poll
```

### Project/Policy

```text
GET /api/plugin/projects
GET /api/plugin/projects/{project}/repositories
GET /api/plugin/repositories/{repo}/policy
GET /api/plugin/repositories/{repo}/instructions
GET /api/plugin/tasks/{task}/context
```

### Runs

```text
POST /api/plugin/runs/start
POST /api/plugin/runs/{run}/heartbeat
POST /api/plugin/runs/{run}/finish
POST /api/plugin/runs/{run}/events
```

### Genesis Import

```text
POST /api/plugin/repositories/{repo}/genesis-imports/start
PUT  /api/plugin/genesis-imports/{import}/artifacts/{artifact}/chunks/{chunk}
POST /api/plugin/genesis-imports/{import}/finalize
GET  /api/plugin/genesis-imports/{import}/status
```

### Delta Sync

```text
POST /api/plugin/runs/{run}/local-snapshots
POST /api/plugin/runs/{run}/delta-syncs
POST /api/plugin/runs/{run}/artifacts
```

### Wiki Proposals

```text
POST /api/plugin/runs/{run}/wiki/proposals
```

## Security Requirements

- LLM never receives API token.
- Plugin token is stored outside the repository.
- Server stores only token hashes.
- Every request is scoped.
- Every write is audited.
- Project policies are enforced server-side.
- Code upload amount is controlled by code exposure policy.
- Secret/path scanning before upload.
- PM has no code write permission.
- Git integration is optional and read-only by default.

## Testing Strategy

For each logical block, provide tests.

Examples:

- token hash validation;
- scope enforcement;
- unauthorized command denial;
- Genesis Import manifest validation;
- artifact hash validation;
- Delta Sync payload validation;
- PM cannot access code-write commands;
- local snapshot is not treated as remote Git fact;
- command registry resolves correct execution location;
- model registry maps model to runtime profile;
- code exposure policy blocks raw patch upload when not allowed.

## First Codex Task Recommendation

First task should not be application implementation.

First task should create a stable specification layer:

```text
docs/ai-devboard/03_DOMAIN_MODEL.md
docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md
docs/ai-devboard/05_GENESIS_IMPORT.md
docs/ai-devboard/06_DELTA_SYNC.md
docs/ai-devboard/07_SECURITY_MODEL.md
docs/ai-devboard/08_IMPLEMENTATION_STEPS.md
```

After those are reviewed, implementation can start.
