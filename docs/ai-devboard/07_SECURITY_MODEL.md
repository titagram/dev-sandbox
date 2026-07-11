# AI DevBoard Security Model

This document defines the V1 security requirements for AI DevBoard.

The core security principle is:

```text
LLMs use local plugin tools.
The plugin talks to the backend.
LLMs never receive backend API tokens.
```

## Trust Boundaries

V1 trust boundaries:

```text
Human dashboard session
Backend Laravel application
PostgreSQL database
Filesystem artifact storage
Neo4j graph database
Python plugin local process
Local working copy
LLM client/tool caller
```

Backend does not directly read source code in V1. Source code content reaches the backend only through plugin-uploaded artifacts that pass repository policy and safety checks.

## Authentication

Dashboard users authenticate with email/password.

Password hashing:

```text
Argon2id preferred
bcrypt acceptable
```

Plugin authentication uses one-time-shown tokens:

```text
devb_live_<token_id>|<random_secret>
```

Server stores:

```text
token_id
token_prefix
token_hash
user_id
device_id
scopes
expires_at
revoked_at
last_used_at
```

The backend must not store the plaintext secret.

## Token Storage

Plugin credentials are stored outside project repositories.

V1 path:

```text
~/.config/devboard/credentials.json
```

Required safeguards:

- create parent directory with user-only permissions when supported;
- create credentials file with user-only permissions when supported;
- never copy credentials into `.devboard/`;
- never include credentials in generated AGENTS files;
- never include credentials in MCP responses;
- never include credentials in artifact bundles;
- redact token-like values from logs and error messages;
- bind the credential record to a normalized server origin;
- do not reuse a saved token or device secret when only the destination URL changes;
- require HTTPS except for explicit loopback development endpoints;
- write credential and repository state files atomically without following symlinks.

## Device Registration

A token can register a device.

Device registration records:

```text
device_id
user_id
fingerprint_hash
platform_os
platform_arch
plugin_version
status
last_seen_at
```

New-device registration also returns a one-time signing secret. The plugin stores it outside the repository and never returns it to the LLM or normal command output. Device-bound requests use an HMAC over method, exact request URI, timestamp, and body hash; stale timestamps, body mismatches, and device mismatches are rejected.

Rules:

- device registration is required before repository link and artifact upload;
- revoked devices cannot call plugin endpoints;
- token revocation invalidates future plugin requests;
- device identity is not a substitute for scopes.

## Authorization

Every backend write checks:

```text
authenticated actor
device status, for plugin calls
token scopes
token user and device ownership of the target run
role permissions
repository policy
runtime profile
command registry permission
```

Denied writes append `permission.denied` to audit logs.

## Role Matrix

```text
Admin
- manage users, roles, tokens, projects, repositories, policies, audit, commands, models.

PM
- read projects, repositories, runs, artifacts, graph, audit summaries.
- write tasks, Kanban, business wiki, and project status.
- cannot write code or grant code-write permissions.

Developer
- read projects, repositories, policies, graph, artifacts, wiki.
- write runs, upload artifacts through plugin, update wiki, manage task execution.

Sysadmin
- read system health, environment inventory, deploy status, logs, audit, graph.
- manage operational runbooks where permitted.

Agent
- read context/policies needed for runs.
- write run events, artifacts, graph imports, and wiki revisions according to token scopes.
- cannot access raw secrets.
```

## Code Exposure Policy

V1 default:

```text
full_code_artifacts
```

Allowed policy values:

```text
metadata_only
symbol_index
symbol_index_plus_snippets
patches_only
full_code_artifacts
full_code_mirror
```

V1 implements `full_code_artifacts` first and keeps the enum ready for stricter project policies.

Policy enforcement points:

- before local scan;
- before artifact bundle creation;
- before chunk upload;
- during backend finalize;
- before wiki revision write;
- before graph import.

## Hybrid Block/Warn Safety

V1 uses `hybrid_block_warn`.

Hard-block:

```text
.env files
private keys
certificates
access tokens
database dumps
credential exports
secret-bearing backup files
```

Warn/exclude:

```text
vendor directories
node_modules directories
cache directories
build outputs
dist outputs
generated files
oversized files
uploads/storage directories
```

Rules:

- hard-blocked content must not be uploaded;
- artifact paths must resolve inside the generated bundle and must not be symlinks;
- manifest size, hash, and chunk count are verified locally before upload;
- a missing or malformed security report fails closed;
- blocked findings stop upload/finalize by default;
- CLI/MCP callers can continue only with explicit local approval through `allow_blocked_security_findings`;
- approved blocked findings are recorded as `security.blocked_upload_approved` with path/reason metadata only;
- warned content can be excluded and recorded;
- every block and warning appears in `security-report.json`;
- dashboard run detail shows blocked and warned categories.

## Artifact Security

Artifact uploads require:

```text
artifact manifest
chunk hash
full artifact hash
artifact type allowlist
schema version
repository id
run id
producer
source metadata
```

Backend validation rejects:

- chunks not declared by manifest;
- mismatched chunk hash;
- mismatched artifact hash;
- unsupported artifact type;
- unsupported schema version;
- blocked content evidence;
- repository id mismatch;
- uploads after run finish.

Artifact storage rules:

- store validated artifacts outside web root;
- never execute uploaded artifacts;
- use generated storage names, not user-provided paths;
- preserve rejected artifact metadata for audit without serving content to dashboard users;
- allow deletion only through retention policy or Admin action recorded in audit logs.

## Wiki Security

Plugin/AI may write wiki revisions directly in V1.

Required controls:

- every revision has source metadata;
- every technical claim has evidence refs or `needs_verification`;
- source status is visible in dashboard;
- old revisions remain available;
- conflicting code evidence marks pages `conflict_with_code`;
- PM can edit wiki but cannot change analyzer evidence records.

## Graph Security

Neo4j is required in V1.

Rules:

- backend imports graph data only from validated artifacts;
- plugin never receives Neo4j credentials;
- dashboard graph queries go through backend authorization;
- graph import writes audit records;
- graph nodes include snapshot provenance;
- stale graph projections remain distinguishable from active snapshots.

## LLM Safety

LLMs may call MCP tools exposed by the local plugin.

MCP tools must:

- avoid returning backend tokens;
- redact credentials and secret-like values;
- expose safe summaries and ids;
- require plugin-side policy checks before upload;
- log tool calls as run events when they affect backend state.

Generated AGENTS/context files must:

- contain instructions, run ids, project ids, and safe context;
- not contain backend tokens;
- not contain secrets from local files;
- be written under `.devboard/`;
- be regenerable.

## Provider Endpoint Transport

`verified_from_code`: Admin-managed model provider base URLs are revalidated at use time, not only when saved. Runtime provider requests use `App\Assistants\ProviderHttpClient`, which resolves every A and AAAA answer immediately before dispatch through `ProviderEndpointPolicy`, rejects unresolved or non-public answers, preserves the original URL hostname, disables redirects, and pins the validated address set with cURL `CURLOPT_RESOLVE` (`host:port:ip`). If cURL pinning constants are unavailable, provider dispatch fails closed.

`verified_from_code`: The pinned transport is used for Admin model discovery, OpenCode validation, server-side agent work chat calls, and real OpenAI-compatible Task Clarifier, Backlog Triage, and Hades Intake Normalizer provider calls. Laravel AI SDK fake-agent paths remain available for deterministic tests, but real stored provider URLs are not dispatched through an unpinned SDK transport.

## Audit Requirements

Audit logs are append-only.

Required audited events:

```text
token.created
token.revoked
device.registered
device.revoked
policy.updated
repository.linked
run.started
run.heartbeat_missed
run.finished
artifact.uploaded
artifact.rejected
genesis.finalized
delta.finalized
wiki.updated
graph.imported
security.blocked_upload
security.blocked_upload_approved
permission.denied
```

Audit payloads must avoid raw secrets and full token values.

## Failure Mode Requirements

Token revoked:

- plugin receives `token_revoked`;
- request is denied;
- audit records denied request.

Secret scan hard-block:

- plugin excludes blocked file content;
- upload/finalize is blocked by default when `security-report.json` has blocked findings;
- explicit local approval can continue and must be audited as `security.blocked_upload_approved`;
- dashboard shows blocked category and path pattern;
- audit records blocked or approved-blocked outcomes without raw secret values.

Hash mismatch:

- backend rejects artifact;
- import remains failed or uploading;
- previous active snapshot remains active.

Neo4j import failure:

- backend preserves uploaded artifacts;
- Genesis/Delta status becomes failed;
- dashboard shows graph import failure;
- no partial graph projection is promoted active.

Wiki evidence missing:

- backend rejects `verified_from_code`;
- plugin may resend as `needs_verification`;
- audit records rejected write if schema validation fails.

## Security Test Scenarios

Required tests:

- token hash validation accepts correct token and rejects wrong secret;
- revoked token cannot call plugin endpoints;
- registered device is required for repository link and upload;
- missing scope blocks artifact upload;
- PM cannot access code-write command;
- Agent cannot manage tokens or policies;
- `.env` file blocks unsafe finalize;
- private key content is redacted from logs;
- warned directories are excluded and recorded;
- artifact hash mismatch rejects finalize;
- LLM-facing MCP response never contains token text;
- wiki `verified_from_code` revision without evidence is rejected;
- graph import cannot run from unvalidated artifact.
