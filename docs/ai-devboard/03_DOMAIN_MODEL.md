# AI DevBoard Domain Model

This document defines the V1 domain model for AI DevBoard.

It is the implementation source of truth for the first backend schema and the object model shared by the Laravel backend, Inertia React dashboard, Python plugin, and MCP tools.

## V1 Product Boundaries

DevBoard V1 is a self-hosted system for one team workspace.

V1 has:

- one implicit workspace per installation;
- multiple users;
- multiple projects;
- multiple repositories per project;
- local-only Git integration;
- PostgreSQL as the primary relational store;
- filesystem storage for uploaded artifacts;
- Neo4j as a required graph service;
- Python plugin-generated code artifacts as the only source of code content.

V1 does not include:

- SaaS multi-tenancy;
- billing;
- backend access to Git remote source code;
- centralized agent execution;
- direct LLM access to backend API tokens;
- long-term support for `ai-sandbox` as a separate runtime product.

## Source Metadata Rules

Every stored technical fact must carry source metadata.

Required fields for fact-like records:

```text
source_type
source_status
evidence_refs
observed_at
producer
repository_id
local_snapshot_id, when local code produced the fact
run_id, when a run produced the fact
```

Allowed `source_type` values for V1:

```text
local_plugin_snapshot
local_plugin_diff
local_analyzer
server_history
user_manual
ai_generated
```

Reserved future `source_type` values:

```text
remote_git
remote_ci
remote_pr
```

Allowed `source_status` values for V1:

```text
verified_from_code
developer_provided
ai_generated
needs_verification
stale
conflict_with_code
```

Rules:

- `verified_from_code` requires a local analyzer artifact, file hash, command output, schema, route output, or parsed code evidence.
- `developer_provided` requires an authenticated human user action.
- `ai_generated` requires an agent/plugin producer and must include evidence refs or a `needs_verification` follow-up.
- `needs_verification` is used for gaps, partial imports, safety-filtered content, failed commands, and unconfirmed assumptions.
- `stale` is set when a newer Genesis Import or Delta Sync invalidates the evidence.
- `conflict_with_code` is set when manual or AI content disagrees with the latest verified local analyzer evidence.

The dashboard must visually distinguish local plugin facts from server-maintained state. In V1, local plugin facts are authoritative for the uploaded snapshot, not for unobserved local realtime code or remote Git state.

## Core Entities

### User

Human account for dashboard access.

Required fields:

```text
id
name
email
password_hash
status: active | disabled
last_login_at
created_at
updated_at
```

Rules:

- passwords are hashed with Argon2id or bcrypt;
- users may hold multiple roles;
- disabled users cannot use dashboard sessions or create plugin tokens.

### Role

Named permission group.

V1 roles:

```text
Admin
PM
Developer
Sysadmin
Agent
```

`Agent` is used for plugin/API identity and must not be assignable as a normal dashboard-only role without an API token/device context.

### Permission

Atomic authorization capability.

Required permission groups:

```text
users.manage
roles.manage
tokens.manage
projects.read
projects.write
repositories.read
repositories.write
tasks.read
tasks.write
runs.read
runs.write
artifacts.read
artifacts.write
wiki.read
wiki.write
policies.read
policies.write
graph.read
graph.write
audit.read
system.health.read
```

Role defaults:

```text
Admin: all permissions
PM: projects.read, repositories.read, tasks.read, tasks.write, runs.read, artifacts.read, wiki.read, wiki.write, graph.read, audit.read
Developer: projects.read, repositories.read, tasks.read, tasks.write, runs.read, runs.write, artifacts.read, artifacts.write, wiki.read, wiki.write, policies.read, graph.read
Sysadmin: projects.read, repositories.read, runs.read, artifacts.read, audit.read, system.health.read, graph.read
Agent: projects.read, repositories.read, runs.write, artifacts.write, wiki.write, policies.read, graph.write
```

PM cannot receive code-write permissions in V1.

### ApiToken

One-time-shown token for plugin/API access.

Required fields:

```text
id
token_prefix
token_hash
user_id
device_id, nullable until registration
name
scopes
expires_at
revoked_at
last_used_at
created_at
```

Displayed token format:

```text
devb_live_<token_id>|<random_secret>
```

Server stores only a hash of `<random_secret>`.

### Device

Registered local machine or plugin installation.

Required fields:

```text
id
user_id
name
fingerprint_hash
platform_os
platform_arch
plugin_version
last_seen_at
status: pending | active | revoked
created_at
updated_at
```

Device registration binds an API token to a machine identity. The plugin may rotate local credentials without changing repository linkage.

### Project

Business/product container.

Required fields:

```text
id
name
slug
description
status: active | archived
default_code_exposure_policy
created_by_user_id
created_at
updated_at
```

V1 default policy:

```text
full_code_artifacts
```

### Repository

One codebase inside a project.

Required fields:

```text
id
project_id
name
slug
default_branch
local_only: true
code_exposure_policy
protected_paths
excluded_paths
stack_hints
graph_enabled: true
created_at
updated_at
```

Rules:

- V1 Git mode is always `local_only`;
- backend does not read remote source code;
- branch and commit fields are plugin-reported facts until future remote Git integration is enabled;
- tasks may link to multiple repositories.

### LocalWorkspace

A developer's local checkout linked to a repository.

Required fields:

```text
id
repository_id
device_id
local_root_hash
display_path
current_branch
last_head_sha
dirty_status
last_snapshot_id
last_seen_at
created_at
updated_at
```

The backend stores display paths for operator clarity, but must not treat a path as a stable secret or identity.

### Task

PM/developer work item.

Required fields:

```text
id
project_id
title
description
status_column_id
priority: low | normal | high | urgent
risk_level: low | medium | high | critical
owner_user_id, nullable
created_by_user_id
due_at, nullable
created_at
updated_at
```

Rules:

- a run may be linked to a task, but task linkage is optional;
- tasks can link to repositories, branches, runs, artifacts, wiki pages, and graph nodes;
- PM can create and update tasks but cannot grant code-write permissions.

### KanbanBoard

Project board visible as the dashboard home.

Required fields:

```text
id
project_id
name
is_default
created_at
updated_at
```

### KanbanColumn

Board status column.

Required fields:

```text
id
board_id
name
position
status_key
wip_limit, nullable
created_at
updated_at
```

Default columns:

```text
Backlog
Ready
In Progress
Blocked
Review
Done
```

### Run

An observed plugin, agent, or deterministic command session.

Required fields:

```text
id
project_id
repository_id, nullable
local_workspace_id, nullable
task_id, nullable
device_id
started_by_user_id
runtime_profile
status
branch
base_branch
base_sha
head_sha, nullable
summary
risk_level
started_at
finished_at, nullable
created_at
updated_at
```

Allowed V1 statuses:

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
```

Reserved future statuses:

```text
branch_pushed
pr_opened
merged
```

V1 must not imply pushed branch, PR, or merge state because Git remote integration is not present.

### RunEvent

Append-only timeline entry for a run.

Required fields:

```text
id
run_id
event_type
severity: info | warning | error | critical
message
payload
created_at
```

Run events are never edited. Corrections are appended as later events.

### Artifact

Stored output from plugin, backend, analyzer, or command.

Required fields:

```text
id
project_id
repository_id, nullable
run_id, nullable
artifact_type
storage_path
sha256
size_bytes
mime_type
schema_version
status: uploading | uploaded | validated | rejected | imported
producer
created_at
updated_at
```

Required artifact types:

```text
genesis_manifest
file_inventory
file_hashes
symbol_index
relation_index
graph_snapshot
route_index
entity_model_index
migration_schema_index
dependency_inventory
test_map
env_inventory
metrics
wiki_pages
analysis_quality_report
security_report
delta_manifest
diff_summary
patch_bundle
risk_report
command_output
```

### GenesisImport

First full repository analysis.

Required fields:

```text
id
project_id
repository_id
local_workspace_id
run_id
status: started | uploading | validating | importing | active | failed | aborted
manifest_artifact_id
snapshot_id
base_branch
base_sha
head_sha
started_at
finished_at, nullable
created_at
updated_at
```

Only one Genesis Import can be active per repository at a time.

### DeltaSync

Incremental repository update after local changes.

Required fields:

```text
id
project_id
repository_id
local_workspace_id
run_id
status: started | uploading | validating | imported | failed | aborted
base_snapshot_id
new_snapshot_id
branch
base_sha
head_sha, nullable
dirty_status
changed_file_count
risk_level
started_at
finished_at, nullable
created_at
updated_at
```

Delta Sync never mutates historical snapshots. It creates a new snapshot and marks affected records stale or superseded.

### Snapshot

Backend representation of a plugin-observed repository state.

Required fields:

```text
id
project_id
repository_id
local_workspace_id
source_type
branch
base_sha
head_sha, nullable
dirty_status
file_inventory_artifact_id
graph_snapshot_artifact_id
created_by_run_id
created_at
```

Rules:

- a snapshot is a local plugin fact;
- a snapshot is not remote Git truth;
- the UI must label local snapshots clearly.

### WikiPage

Knowledge page.

Required fields:

```text
id
project_id
repository_id, nullable
slug
title
page_type: business | technical | runbook | audit
current_revision_id
source_status
created_at
updated_at
```

### WikiRevision

Versioned wiki content.

Required fields:

```text
id
wiki_page_id
author_user_id, nullable
author_device_id, nullable
producer: human | plugin | analyzer | ai
source_type
source_status
content_markdown
evidence_refs
created_at
```

Wiki revisions are append-only. Direct AI/plugin wiki writes create normal revisions, not proposals, but must include source metadata and evidence.

### AuditLog

Append-only security and governance record.

Required fields:

```text
id
actor_user_id, nullable
actor_device_id, nullable
actor_type: user | plugin | system
action
target_type
target_id
ip_address
user_agent
payload
created_at
```

Required audited actions:

```text
token.created
token.revoked
device.registered
policy.updated
repository.linked
run.started
run.finished
artifact.uploaded
artifact.rejected
genesis.finalized
delta.finalized
wiki.updated
security.blocked_upload
permission.denied
```

## Command Registry

Commands are executable capabilities. Artifacts are their outputs. Endpoints only transport command requests and outputs.

Required fields:

```text
id
name
category
description
execution_location: server | plugin | hybrid
requires_code_access
requires_git_remote
requires_llm
writes_code
writes_server_state
output_artifact_type
allowed_roles
allowed_runtime_profiles
scopes_required
risk_level
requires_approval
input_schema
output_schema
enabled
created_at
updated_at
```

V1 required commands:

```text
plugin.auth.check
plugin.device.register
repository.link_local
repository.pull_policy
repository.pull_context
run.start
run.heartbeat
run.finish
genesis.scan
genesis.upload
delta.scan
delta.upload
wiki.write_revision
graph.import_snapshot
```

## Runtime Profiles

Required V1 runtime profiles:

```text
human_pm
human_dev
human_sysadmin
agent_plugin
deterministic_tool
frontier_writer
frontier_reviewer
compact_readonly
```

Runtime profiles determine command access, approval requirements, and escalation. They do not permanently encode model capability assumptions.

## Model Registry

Required fields:

```text
id
provider
model_name
local_or_cloud
max_context
allowed_runtime_profiles
can_write
can_review
can_plan
trust_level: low | medium | high
cost_level: low | medium | high
approval_requirements
enabled
created_at
updated_at
```

The model registry is managed by Admin users. The plugin can report local model availability but cannot grant itself new runtime permissions.

## Storage Responsibilities

PostgreSQL stores:

- domain rows;
- JSON payloads and metadata;
- source metadata;
- run timeline;
- wiki revisions;
- audit records;
- artifact manifests and storage pointers.

Filesystem storage stores:

- uploaded artifact chunks;
- validated artifact files;
- retained manifest bundles;
- import logs.

Neo4j stores:

- code intelligence nodes;
- code intelligence relations;
- wiki/task/run links to code nodes;
- graph import metadata.

The backend must be able to rebuild Neo4j graph state from validated artifacts stored in PostgreSQL and filesystem storage.

