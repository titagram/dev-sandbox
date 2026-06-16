# AI DevBoard Genesis Import

Genesis Import is the first full, reproducible analysis of a repository.

In V1, Genesis Import is the first implementation slice after the specification package. The acceptance target is an end-to-end happy path: token copied from dashboard, device registered, repository linked, local code analyzed, artifacts uploaded, PostgreSQL and Neo4j updated, and dashboard state visible.

## Ownership

Plugin responsibilities:

- inspect local Git state;
- read the working copy;
- run environment and stack detection;
- run secret/path safety checks;
- run tree-sitter/Graphify analysis;
- generate the Genesis bundle;
- upload manifest and artifacts in chunks;
- report run events and final status.

Backend responsibilities:

- authenticate token and device;
- enforce repository policy;
- allocate run, Genesis Import, artifact, and snapshot ids;
- receive chunks;
- validate hashes and schema versions;
- persist artifact metadata in PostgreSQL;
- store artifact files on the filesystem;
- import graph artifacts into Neo4j;
- write or update wiki revisions with evidence;
- expose import state in dashboard.

## Required Flow

```text
1. Plugin checks token.
2. Plugin registers device if needed.
3. Plugin links local workspace to project/repository.
4. Plugin pulls RepositoryPolicy.
5. Plugin starts a run with run_type=genesis_import.
6. Plugin scans local repository.
7. Plugin runs hybrid block/warn safety checks.
8. Plugin generates Genesis bundle.
9. Plugin starts Genesis Import on backend.
10. Plugin uploads manifest and artifacts by chunks.
11. Plugin finalizes Genesis Import.
12. Backend validates hashes and schemas.
13. Backend creates a Snapshot.
14. Backend imports graph into Neo4j.
15. Backend upserts wiki revisions.
16. Backend marks Genesis Import active.
17. Plugin finishes run.
```

## Genesis Bundle Layout

The plugin creates a local bundle under:

```text
.devboard/artifacts/genesis/<run_id>/
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

The local bundle is regenerable and must not be committed.

## GenesisBundle Manifest

`manifest.json` is the bundle root.

Required shape:

```json
{
  "protocol_version": "v1",
  "bundle_type": "genesis_import",
  "schema_version": "v1",
  "project_id": "proj_01J...",
  "repository_id": "repo_01J...",
  "local_workspace_id": "lw_01J...",
  "run_id": "run_01J...",
  "branch": "main",
  "base_branch": "main",
  "base_sha": "abc123",
  "head_sha": "abc123",
  "dirty_status": "clean",
  "generated_at": "2026-06-16T15:30:00Z",
  "producer": {
    "name": "devboard-python-plugin",
    "version": "0.1.0",
    "analyzer": "tree-sitter/graphify"
  },
  "policy": {
    "git_mode": "local_only",
    "code_exposure": "full_code_artifacts",
    "graph_required": true,
    "secret_scan_mode": "hybrid_block_warn"
  },
  "artifacts": []
}
```

Each artifact entry uses the `ArtifactManifest` shape defined in `04_PLUGIN_SERVER_CONTRACT.md`.

## Required Artifacts

### file-inventory.json

Purpose: enumerate included and excluded files.

Required fields per file:

```text
path
kind: source | config | test | docs | migration | asset | unknown
size_bytes
language, nullable
included: true | false
exclusion_reason, nullable
```

### file-hashes.json

Purpose: stable hash evidence.

Required fields per file:

```text
path
sha256
size_bytes
mtime_observed_at
source_status: verified_from_code
```

### dependency-inventory.json

Purpose: package and framework visibility.

Required fields:

```text
package_files
detected_frameworks
dependencies
dev_dependencies
lockfiles
```

### env-inventory.json

Purpose: non-secret environment and command inventory.

Allowed content:

- required env variable names;
- detected runtime versions;
- Docker compose service names;
- start/test/build command names.

Forbidden content:

- env values;
- API keys;
- database passwords;
- private certificates.

### symbol-index.json

Purpose: code symbol catalog.

Required symbol fields:

```text
id
kind: class | method | function | route | model | table | migration | job | event | listener | command | test
name
file_path
line_start
line_end
language
signature, nullable
source_status
evidence_refs
```

### relation-index.json

Purpose: code relationship catalog.

Allowed relation types:

```text
CALLS
USES
MAPS_TO
OWNS
DOCUMENTS
COVERS
CREATES_TABLE
TOUCHES
DEPENDS_ON
MODIFIED
```

Required relation fields:

```text
id
type
source_symbol_id
target_symbol_id
confidence: exact | high | medium | low
evidence_refs
```

### graph-snapshot.json

Purpose: Neo4j import source.

Required fields:

```text
nodes
relationships
schema_version
snapshot_hash
```

Graph node labels must prefer code intelligence entities over raw AST nodes:

```text
File
Class
Method
Function
Route
Controller
Model
Table
Migration
Job
Event
Listener
Command
Test
WikiPage
Task
Run
Commit
```

### route-index.json

Purpose: user-facing or API route catalog.

Required route fields:

```text
method
path
handler_symbol_id
middleware
file_path
evidence_refs
```

### entity-model-index.json

Purpose: domain object catalog.

Required fields:

```text
name
kind: model | entity | dto | resource | schema
file_path
fields
relations
evidence_refs
```

### migration-schema-index.json

Purpose: database schema evidence.

Required fields:

```text
migrations
tables
columns
indexes
foreign_keys
evidence_refs
```

### test-map.json

Purpose: connect tests to code under test.

Required fields:

```text
test_files
test_symbols
covered_symbols
test_commands
confidence
```

### metrics.json

Purpose: dashboard and risk summaries.

Required metrics:

```text
file_count
included_file_count
excluded_file_count
language_counts
symbol_count
relation_count
route_count
test_count
migration_count
artifact_count
warning_count
blocked_item_count
```

### wiki-pages.json

Purpose: direct technical wiki updates.

Each page uses:

```text
slug
title
page_type
content_markdown
source_type
source_status
evidence_refs
```

Plugin/analyzer-generated wiki pages may be written directly in V1. They must carry evidence and source status.

### analysis-quality-report.json

Purpose: explain confidence and gaps.

Required fields:

```text
overall_status: complete | partial | failed
warnings
unsupported_languages
skipped_paths
failed_commands
low_confidence_sections
recommended_followups
```

### security-report.json

Purpose: record block/warn safety results.

Required fields:

```text
mode: hybrid_block_warn
blocked_items
warnings
excluded_paths
secret_patterns_matched
safe_to_upload
```

`safe_to_upload` must be false if any hard-block item remains in the upload set.

## Safety Policy

Hard-block examples:

```text
.env
private keys
certificates
access tokens
database dumps
credential exports
secret-bearing backup files
```

Warning examples:

```text
vendor/
node_modules/
cache/
build/
dist/
generated files
oversized files
uploads/storage directories
```

Rules:

- hard-blocked content is not uploaded;
- if hard-blocked content is required to satisfy `full_code_artifacts`, finalize fails with `secret_scan_blocked`;
- warning content may be excluded and recorded in `analysis-quality-report.json`;
- every exclusion must be visible in the dashboard.

## Backend Validation

Finalize must validate:

- protocol version;
- manifest schema;
- required artifact presence;
- each artifact SHA-256;
- each uploaded chunk SHA-256;
- allowed artifact type;
- repository policy compliance;
- security report `safe_to_upload`;
- graph snapshot schema;
- wiki revision evidence shape.

Failure handling:

- missing chunk returns `artifact_chunk_missing`;
- hash mismatch returns `artifact_hash_mismatch`;
- hard-block violation returns `secret_scan_blocked`;
- schema failure returns `schema_validation_failed`;
- Neo4j import failure marks Genesis Import `failed` but preserves uploaded artifacts for audit.

## Snapshot Activation

A Genesis Import becomes active only after:

```text
manifest validated
all required artifacts validated
snapshot row created
Neo4j import completed
wiki revisions written or marked failed
run event appended
```

Activation effects:

- previous active snapshot for the repository becomes superseded;
- wiki pages backed by old evidence are marked stale when impacted;
- project dashboard shows repository as initialized;
- Kanban/project detail can link tasks and runs to graph nodes.

## Neo4j Import Contract

Neo4j import must be idempotent per `snapshot_id`.

Required graph metadata node:

```text
(:DevBoardSnapshot {
  snapshot_id,
  project_id,
  repository_id,
  run_id,
  source_type,
  branch,
  base_sha,
  head_sha,
  imported_at
})
```

Import rules:

- delete or supersede previous graph projection for the same repository snapshot scope;
- create nodes with stable ids from artifact ids and symbol ids;
- create relations only from validated `relation-index.json` and `graph-snapshot.json`;
- link `Run`, `WikiPage`, and `Task` nodes when ids are present.

## Acceptance Criteria

The first implementation slice is accepted when:

- an Admin creates a plugin token in the dashboard;
- the Python plugin registers a device with that token;
- the plugin links a local repository to a DevBoard repository;
- the plugin runs Genesis Import locally;
- artifacts upload with manifest/chunk/finalize;
- backend validates hashes and schema;
- PostgreSQL stores domain rows and artifact metadata;
- filesystem storage contains validated artifacts;
- Neo4j contains graph nodes and relationships for the snapshot;
- dashboard shows repository initialized, run detail, artifact list, and graph status;
- hard-block secret detection prevents unsafe upload.

