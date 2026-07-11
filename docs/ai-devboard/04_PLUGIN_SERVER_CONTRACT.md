# AI DevBoard Plugin Server Contract

This document defines the V1 protocol between the Python DevBoard plugin and the Laravel backend.

The plugin is both a CLI and MCP server. Codex, Claude Code, and local models interact with local plugin tools. The plugin alone talks to the backend with API credentials.

## Protocol Rules

V1 protocol identifier:

```text
devboard-plugin-api/v1
```

All plugin requests must include:

```text
Authorization: Bearer devb_live_<token_id>|<secret>
Accept: application/json
Content-Type: application/json
X-DevBoard-Protocol: v1
X-DevBoard-Plugin-Version: <semver>
X-DevBoard-Device-Id: <device_id>, after registration
```

Requests made with a device-bound token also include:

```text
X-DevBoard-Timestamp: <unix_seconds>
X-DevBoard-Content-SHA256: <sha256_of_exact_request_body>
X-DevBoard-Signature: v1=<hmac_sha256>
```

The signature canonical value is `METHOD\nREQUEST_URI\nTIMESTAMP\nBODY_SHA256`. The signing key is the SHA-256 hex digest of the one-time device secret. `REQUEST_URI` includes the path and query string exactly as sent.

All JSON payloads include:

```json
{
  "protocol_version": "v1"
}
```

The backend rejects unsupported protocol versions with:

```json
{
  "error": {
    "code": "protocol_version_unsupported",
    "message": "Unsupported plugin protocol version.",
    "supported_versions": ["v1"]
  }
}
```

## Authentication Model

V1 supports dashboard-generated tokens copied into the plugin.

Token format:

```text
devb_live_<token_id>|<random_secret>
```

Server-side storage:

```text
token_id
token_prefix
token_hash
user_id
device_id, nullable
scopes
expires_at
revoked_at
last_used_at
```

Token rules:

- the full token is shown once in the dashboard;
- the server stores only a hash of the secret;
- the plugin stores credentials outside the project repository;
- stored credentials are bound to their normalized server origin and cannot be reused when only the server URL is overridden;
- HTTPS is required outside explicit loopback development origins;
- token values must never be written to `.devboard/`, generated AGENTS files, wiki content, logs, artifacts, or committed files.

Recommended local credential path:

```text
~/.config/devboard/credentials.json
```

The file must be created with strict user-only permissions where the OS supports them.

## Local Plugin Files

For each linked repository, the plugin may create:

```text
.devboard/
  AGENTS.generated.md
  cache/
  artifacts/
  state.json
```

Rules:

- `.devboard/` is temporary and regenerable;
- the plugin should add `.devboard/` to `.git/info/exclude` when possible;
- the plugin must not modify project `.gitignore` in V1 unless a human explicitly asks;
- `.devboard/AGENTS.generated.md` must not include API tokens or secrets;
- `.devboard/state.json` may include server ids, local workspace ids, run ids, and non-secret local state.

## CLI Surface

Required V1 CLI commands:

```text
devboard auth check
devboard auth register-device
devboard projects list
devboard repos link
devboard repos policy
devboard context pull
devboard runs start
devboard runs heartbeat
devboard runs finish
devboard genesis run
devboard delta run
devboard artifacts upload
devboard wiki write
```

CLI commands call the same Python core used by MCP tools.

## MCP Tool Surface

Required V1 MCP tools:

```text
devboard_auth_check
devboard_get_context
devboard_start_run
devboard_heartbeat_run
devboard_finish_run
devboard_genesis_import
devboard_delta_sync
devboard_upload_artifact
devboard_write_wiki_revision
```

MCP tools must not expose raw backend tokens to the LLM. Tools return domain ids, statuses, summaries, and safe errors only. An MCP `server_url` override must not carry credentials saved for another origin.

## Endpoint Summary

Base path:

```text
/api/plugin/v1
```

Auth and device:

```text
POST /auth/check
POST /devices/register
```

Project and repository:

```text
GET  /projects
GET  /projects/{project_id}/repositories
POST /repositories/{repository_id}/local-workspaces
GET  /repositories/{repository_id}/policy
GET  /repositories/{repository_id}/instructions
GET  /tasks/{task_id}/context
```

Runs:

```text
POST /runs
POST /runs/{run_id}/heartbeat
POST /runs/{run_id}/events
POST /runs/{run_id}/finish
```

Genesis Import:

```text
POST /repositories/{repository_id}/genesis-imports
PUT  /genesis-imports/{import_id}/artifacts/{artifact_id}/chunks/{chunk_index}
POST /genesis-imports/{import_id}/finalize
GET  /genesis-imports/{import_id}
```

Delta Sync:

```text
POST /runs/{run_id}/local-snapshots
POST /runs/{run_id}/delta-syncs
PUT  /delta-syncs/{delta_id}/artifacts/{artifact_id}/chunks/{chunk_index}
POST /delta-syncs/{delta_id}/finalize
```

Wiki:

```text
POST /runs/{run_id}/wiki/revisions
```

## Public Types

### PluginToken

```json
{
  "token_id": "tok_01J...",
  "token_prefix": "devb_live_tok_01J",
  "scopes": ["projects.read", "runs.write", "artifacts.write"],
  "expires_at": "2026-12-31T23:59:59Z",
  "device_id": "dev_01J...",
  "revoked": false
}
```

### DeviceRegistration

Request:

```json
{
  "protocol_version": "v1",
  "name": "Gabriele MacBook Pro",
  "fingerprint_hash": "sha256:...",
  "platform_os": "darwin",
  "platform_arch": "arm64",
  "plugin_version": "0.1.0"
}
```

Response:

```json
{
  "device_id": "dev_01J...",
  "device_secret": "<shown once for a new device>",
  "status": "active",
  "server_time": "2026-06-16T15:30:00Z"
}
```

The plugin persists `device_secret` with the credential record, removes it from CLI/MCP output, and uses it for subsequent signed requests. The backend does not return the secret again for an existing device.

Run mutation endpoints verify that the authenticated token user and bound device own the target run. Repository, workspace, task, snapshot, project, and import references must belong to the same project/repository context.

### RepositoryPolicy

```json
{
  "protocol_version": "v1",
  "repository_id": "repo_01J...",
  "git_mode": "local_only",
  "code_exposure": "full_code_artifacts",
  "graph_required": true,
  "secret_scan": {
    "mode": "hybrid_block_warn",
    "block_patterns": [".env", "private_key", "token", "certificate", "database_dump"],
    "warn_patterns": ["vendor", "cache", "build", "generated", "oversized"]
  },
  "excluded_paths": ["vendor/", "node_modules/", ".git/", "storage/uploads/"],
  "protected_paths": [],
  "max_artifact_bytes": 524288000,
  "chunk_size_bytes": 5242880
}
```

### RunStartPayload

Request:

```json
{
  "protocol_version": "v1",
  "project_id": "proj_01J...",
  "repository_id": "repo_01J...",
  "local_workspace_id": "lw_01J...",
  "task_id": null,
  "run_type": "genesis_import",
  "runtime_profile": "agent_plugin",
  "branch": "main",
  "base_branch": "main",
  "base_sha": "abc123",
  "head_sha": "abc123",
  "dirty_status": "clean"
}
```

Response:

```json
{
  "run_id": "run_01J...",
  "status": "started",
  "heartbeat_interval_seconds": 30
}
```

### ArtifactManifest

```json
{
  "protocol_version": "v1",
  "artifact_id": "art_01J...",
  "artifact_type": "symbol_index",
  "schema_version": "v1",
  "filename": "symbol-index.json",
  "mime_type": "application/json",
  "sha256": "sha256...",
  "size_bytes": 42133,
  "chunk_count": 1,
  "producer": "devboard-python-plugin",
  "source_type": "local_analyzer",
  "source_status": "verified_from_code"
}
```

### ArtifactChunk

Chunk upload path:

```text
PUT /genesis-imports/{import_id}/artifacts/{artifact_id}/chunks/{chunk_index}
```

Chunk metadata headers:

```text
X-DevBoard-Chunk-SHA256: <chunk_hash>
X-DevBoard-Chunk-Size: <bytes>
```

Successful response:

```json
{
  "artifact_id": "art_01J...",
  "chunk_index": 0,
  "status": "received"
}
```

### EvidenceRef

```json
{
  "type": "artifact",
  "artifact_id": "art_01J...",
  "path": "src/Controller/UserController.php",
  "hash": "sha256:...",
  "line_start": 12,
  "line_end": 48,
  "description": "Parsed controller method."
}
```

Allowed evidence types:

```text
artifact
file_hash
command_output
graph_node
wiki_revision
run_event
manual_note
```

### WikiRevisionPayload

```json
{
  "protocol_version": "v1",
  "project_id": "proj_01J...",
  "repository_id": "repo_01J...",
  "slug": "technical/routes",
  "title": "Routes",
  "page_type": "technical",
  "producer": "plugin",
  "source_type": "local_analyzer",
  "source_status": "verified_from_code",
  "content_markdown": "# Routes\n\n...",
  "evidence_refs": []
}
```

### RiskReport

```json
{
  "risk_level": "medium",
  "triggers": ["migrations_changed", "large_diff"],
  "summary": "Database migrations changed and 37 files were touched.",
  "requires_human_review": true,
  "evidence_refs": []
}
```

Allowed `risk_level` values:

```text
low
medium
high
critical
```

## Error Shape

All errors use:

```json
{
  "error": {
    "code": "artifact_hash_mismatch",
    "message": "Uploaded artifact hash does not match manifest.",
    "details": {
      "artifact_id": "art_01J..."
    }
  }
}
```

Required error codes:

```text
unauthorized
forbidden
token_revoked
device_required
scope_missing
protocol_version_unsupported
repository_policy_denied
secret_scan_blocked
artifact_chunk_missing
artifact_hash_mismatch
artifact_finalize_conflict
artifact_chunk_out_of_range
artifact_size_mismatch
run_not_active
schema_validation_failed
rate_limited
server_error
```

### Chunk range and exact size

`size_bytes` is an exact required final size, not an estimate. The backend validates it at two layers:

- During chunk upload, the server sums the bytes of existing expected chunks (indexes `0..chunk_count-1`) plus the incoming chunk. If the total would exceed the declared `size_bytes`, the chunk is rejected with `artifact_size_mismatch` (HTTP 422) and is not stored.
- During finalize assembly, the server streams chunks in declared index order (`0..chunk_count-1`). If the running total exceeds `size_bytes`, or the final assembled size is not exactly equal to `size_bytes`, finalize returns `artifact_size_mismatch` (HTTP 422), the partial assembled file is deleted, and SHA validation is not reached.

A chunk index must be a non-negative integer in the declared range `0..chunk_count-1`. The route chunk parameter is parsed explicitly; negative, non-integer, and overflow values are rejected with `artifact_chunk_out_of_range` (HTTP 422) before any file is created. The server never scans an unbounded chunk directory; it iterates only declared indexes. Chunk accounting is serialized with `artifacts` row locks, so chunk writes and finalization cannot race.

## Idempotency and Retry

Chunk uploads are idempotent by:

```text
import_id or delta_id
artifact_id
chunk_index
chunk_hash
```

Rules:

- re-uploading the same chunk hash returns success;
- re-uploading the same chunk index with a different hash returns `artifact_finalize_conflict`;
- a chunk index outside the declared `0..chunk_count-1` range returns `artifact_chunk_out_of_range` and creates no file;
- a chunk whose bytes would push the total past the declared `size_bytes` returns `artifact_size_mismatch` and is not stored;
- finalize can be retried after server-side transient failure;
- finalize after successful import returns the existing final status;
- finalize with missing chunks returns `artifact_chunk_missing`;
- finalize validates exact assembled size before SHA: a size that does not match `size_bytes` returns `artifact_size_mismatch` and deletes the partial file;
- finalize with mismatched full artifact hash returns `artifact_hash_mismatch`.

## Scopes

Required plugin scopes:

```text
projects.read
repositories.read
policies.read
runs.write
artifacts.write
wiki.write
graph.write
```

The backend checks scopes for every request. The plugin cannot bypass policy by choosing a different endpoint.
