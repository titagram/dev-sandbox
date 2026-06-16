# AI DevBoard Architecture Decisions

This document records the current architectural decisions for the AI DevBoard system.

## 1. Backend as Orchestrator, Not Code Runner

The backend is a coordinator and memory layer. It does not initially run coding agents centrally and does not require mandatory direct access to source code.

Responsibilities:

- users, roles, permissions;
- projects and repositories;
- task and Kanban management;
- wiki and decision history;
- command registry;
- model registry;
- token and device management;
- artifact storage;
- agent run history;
- audit log;
- policy enforcement;
- context pack generation.

## 2. Plugin as Local Execution Bridge

The plugin is responsible for local code access.

It must be able to:

- authenticate with the backend;
- inspect local Git state;
- read the working copy;
- generate local code artifacts;
- generate temporary AGENTS/context files if needed;
- expose safe tools to Codex/Claude/local models;
- upload Genesis Import artifacts;
- upload Delta Sync artifacts;
- report run start/heartbeat/finish;
- request server-side history, wiki, policies, and context packs.

## 3. LLMs Never See Tokens

The correct flow is:

```text
LLM -> plugin -> backend
```

The wrong flow is:

```text
LLM -> backend API directly with token
```

Tokens must never be included in prompts, generated AGENTS files, wiki pages, logs, or committed project files.

## 4. Authentication and Tokens

User dashboard passwords should use bcrypt or Argon2.

Plugin/API tokens should use an `id|secret` style format and store only a hash of the secret server-side.

Recommended approach:

```text
shown token: devb_live_<id>|<random_secret>
database:
  token_id
  token_hash = SHA-256 or HMAC-SHA256(secret)
  user_id
  device_id
  scopes
  expires_at
  revoked_at
  last_used_at
```

The token should be shown only once.

Supported authentication flows:

- dashboard-generated token copied into plugin;
- future browser/device login flow opened by the plugin.

## 5. Token Storage

The plugin should store credentials outside the project repository.

Initial acceptable storage:

```text
~/.config/devboard/credentials.json
```

with strict file permissions.

Future preferred storage:

- macOS Keychain;
- Windows Credential Manager;
- Linux Secret Service.

## 6. AGENTS / Instructions

The system should have master instructions managed centrally by the backend.

Instruction sets may include:

- global master rules;
- project rules;
- role rules;
- task workflow rules;
- model/runtime profile rules;
- reporting rules;
- escalation rules.

The plugin pulls these instructions and generates a temporary local context/AGENTS file.

The generated AGENTS file should not be committed.

Preferred options:

- generate `.devboard/AGENTS.generated.md`;
- exclude `.devboard/` through `.git/info/exclude`;
- use a workspace wrapper outside the actual repository;
- optionally keep only a minimal committed bootstrap `AGENTS.md`.

## 7. Genesis Import

Genesis Import is the first full analysis of a repository.

It is executed locally by the plugin and uploaded to the backend.

It should generate:

- repository metadata;
- branch and commit information;
- file inventory;
- file hashes;
- detected stack/frameworks;
- dependencies;
- AST/code index;
- symbol index;
- relation graph;
- route index;
- entity/model index;
- migration/database schema index;
- test map;
- env inventory;
- deterministic technical wiki;
- metrics;
- analysis quality report.

Genesis Import should be reproducible, auditable, and hash-verifiable.

No generated claim should exist without evidence metadata.

## 8. Delta Sync

After code changes, the plugin sends incremental deltas to the backend.

Delta Sync should include:

- repository id;
- project id;
- workspace id;
- branch;
- base branch;
- base SHA;
- local HEAD SHA when available;
- dirty status;
- staged/unstaged/untracked files;
- changed files;
- additions/deletions;
- file hashes;
- diff summary;
- patch, depending on exposure policy;
- recomputed symbols for affected files;
- recomputed relations for affected files;
- affected wiki pages;
- test output, when run;
- risk report.

The backend stores the delta as a local plugin snapshot unless verified against remote Git.

## 9. Git Integration Modes

Git integration must be optional and configurable per repository.

Possible modes:

```text
local_only
remote_metadata
remote_readonly
remote_mirror
```

### local_only

The backend has no Git access. It receives artifacts from plugins.

### remote_metadata

The backend can read metadata such as branches, commits, PRs, CI status, and tags, but not source code.

### remote_readonly

The backend can read repository contents, ideally via a GitHub/GitLab App with minimum read-only permissions.

### remote_mirror

The backend maintains a repository mirror. This is powerful but increases security exposure and should be considered later.

Default recommendation:

```text
local_only first, remote_metadata optional, remote_readonly advanced.
```

## 10. Code Exposure Policy

Each project/repository must define how much source information may be uploaded.

Possible values:

```text
metadata_only
symbol_index
symbol_index_plus_snippets
patches_only
full_code_artifacts
full_code_mirror
```

The plugin must honor this policy during Genesis Import and Delta Sync.

Before uploading artifacts, the plugin should run a secret/path safety check and exclude:

- `.env` files;
- private keys;
- certificates;
- access tokens;
- database dumps;
- backup files;
- uploads/storage directories;
- vendor/cache/build outputs;
- oversized files.

## 11. Commands and Artifacts

Not every deterministic capability should be a backend HTTP endpoint that directly reads code.

Distinguish:

- commands: things that can be executed;
- artifacts: results produced by commands;
- endpoints: transport/storage access to command outputs.

Some commands execute server-side. Some execute client-side through the plugin.

Example command registry fields:

```text
name
execution_location
requires_code_access
requires_llm
writes_code
output_artifact
allowed_roles
allowed_runtime_profiles
risk_level
requires_approval
input_schema
output_schema
```

## 12. Runtime Profiles

Do not hardcode permanent rules such as "local models can never write".

Instead use runtime profiles managed by the backend:

```text
frontier_writer
frontier_reviewer
compact_readonly
deterministic_tool
human_pm
human_dev
human_sysadmin
```

The superadmin can map models/clients to runtime profiles.

Model registry fields should include:

- provider;
- model name;
- local/cloud;
- max context;
- allowed profiles;
- can_write;
- can_review;
- can_plan;
- trust level;
- cost level;
- approval requirements;
- enabled/disabled.

## 13. Escalation

Escalation must be server-side configurable.

The plugin can suggest an escalation, but the backend enforces policy.

Example escalation triggers:

- high risk score;
- auth/payment/security/privacy touched;
- migrations changed;
- destructive operations;
- test failures;
- high uncertainty;
- large multi-file diff;
- protected paths touched;
- low-confidence model output.

## 14. Roles

### PM

- Kanban dashboard;
- task read/write;
- business wiki read/write;
- technical wiki propose/comment;
- code read-only;
- project status and risk summaries.

### Developer

- local code write through normal Git workflow;
- task/run management;
- context pack access;
- graph/metrics access;
- wiki read/write or propose depending on policy;
- artifact upload.

### Sysadmin

- environment inventory;
- deploy status;
- logs/health visibility;
- operational wiki/runbook management.

### Agent/plugin

- context read;
- metrics read;
- graph read;
- run create/update/finish;
- artifact upload;
- wiki proposal/update depending on policy;
- no direct secret access.

## 15. Multi-repository Model

A project may contain multiple repositories.

Important entities should reference:

```text
project_id
repository_id
workspace_id
branch
base_sha
local_snapshot_id
remote_commit_sha
run_id
```

Tasks may link to multiple repositories.

## 16. Wiki Model

Wiki should support:

- business/manual sections;
- deterministic technical sections;
- source status;
- evidence links;
- revisions;
- author/source tracking;
- staleness detection;
- conflict detection.

Suggested source status values:

```text
verified_from_code
developer_provided
inferred
needs_verification
ai_generated
stale
conflict_with_code
```

## 17. Source Types

Every stored fact should indicate its source.

Examples:

```text
remote_git
local_plugin_snapshot
local_plugin_diff
local_analyzer
server_history
user_manual
ai_generated
```

## 18. Backend Does Not Pretend to Know Local Realtime Code

Until a branch is pushed or Git remote is checked, local code facts are plugin-declared snapshots.

The UI must distinguish:

- remote Git facts;
- local plugin snapshots;
- generated analyzer facts;
- historical facts.

This avoids confusion between local unpushed work and remote project state.
