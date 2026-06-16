# AI DevBoard Delta Sync

Delta Sync is the incremental update format after local code changes.

In V1, Delta Sync is built after Genesis Import. It reuses the same plugin, policy, artifact, security, and upload mechanisms, but limits analysis to changed files and impacted relations where possible.

## Purpose

Delta Sync keeps DevBoard current with local plugin-observed work without pretending to know unobserved realtime code or remote Git state.

Delta Sync must answer:

- what changed locally;
- which files and symbols are affected;
- which graph relations changed;
- which wiki pages are stale or updated;
- which tests ran;
- what risk the change carries;
- which artifacts prove the update.

## Required Flow

```text
1. Plugin detects local changes or user invokes delta sync.
2. Plugin checks token and repository policy.
3. Plugin starts or reuses an active run.
4. Plugin computes changed, staged, unstaged, and untracked files.
5. Plugin runs hybrid block/warn safety checks.
6. Plugin reparses affected files with tree-sitter/Graphify.
7. Plugin recomputes affected symbols, relations, metrics, and wiki pages.
8. Plugin creates DeltaPayload and artifacts.
9. Plugin uploads artifacts in chunks.
10. Plugin finalizes Delta Sync.
11. Backend validates payload, hashes, and policy compliance.
12. Backend creates a new Snapshot.
13. Backend imports graph changes into Neo4j.
14. Backend marks impacted wiki content stale or writes new revisions.
15. Backend updates project dashboard state.
```

## DeltaPayload

Root payload:

```json
{
  "protocol_version": "v1",
  "schema_version": "v1",
  "project_id": "proj_01J...",
  "repository_id": "repo_01J...",
  "local_workspace_id": "lw_01J...",
  "run_id": "run_01J...",
  "base_snapshot_id": "snap_01J...",
  "branch": "feature/devboard",
  "base_branch": "main",
  "base_sha": "abc123",
  "head_sha": "def456",
  "dirty_status": "dirty",
  "changed_files": [],
  "artifact_manifests": [],
  "risk_report": {},
  "generated_at": "2026-06-16T15:30:00Z"
}
```

Allowed `dirty_status` values:

```text
clean
dirty
staged
unstaged
untracked
mixed
unknown
```

## Changed File Entry

```json
{
  "path": "src/Controller/UserController.php",
  "change_type": "modified",
  "git_status": "unstaged",
  "old_sha256": "sha256:...",
  "new_sha256": "sha256:...",
  "additions": 14,
  "deletions": 3,
  "language": "php",
  "safety_status": "allowed",
  "evidence_refs": []
}
```

Allowed `change_type` values:

```text
added
modified
deleted
renamed
copied
type_changed
unknown
```

Allowed `safety_status` values:

```text
allowed
warned
blocked
excluded
```

## Required Delta Artifacts

```text
delta_manifest
file_hashes
diff_summary
symbol_index
relation_index
graph_snapshot
wiki_pages
risk_report
analysis_quality_report
security_report
```

Conditional artifacts:

```text
patch_bundle
route_index
entity_model_index
migration_schema_index
test_map
command_output
metrics
```

Conditional artifact rules:

- `patch_bundle` is allowed because V1 default policy is `full_code_artifacts`, unless the repository policy overrides it;
- `migration_schema_index` is required if migration files changed;
- `route_index` is required if route files or controller route annotations changed;
- `test_map` is required if tests changed or test commands ran;
- `command_output` is required when the plugin reports test/build command results.

## Diff Summary

`diff_summary` must include:

```text
changed_file_count
added_file_count
modified_file_count
deleted_file_count
renamed_file_count
additions
deletions
staged_file_count
unstaged_file_count
untracked_file_count
protected_path_touches
blocked_path_touches
```

The backend stores this summary in PostgreSQL for dashboard filtering.

## Patch Bundle

When allowed, `patch_bundle` contains unified diffs.

Rules:

- patches must not include hard-blocked secret content;
- binary file diffs are summarized, not embedded;
- oversized patch sections are replaced with evidence refs and `needs_verification`;
- the plugin records truncation in `analysis-quality-report.json`.

## Affected Graph Contract

The plugin may send a full graph snapshot or an affected subgraph.

Required fields:

```text
graph_mode: full_snapshot | affected_subgraph
base_snapshot_id
nodes_upserted
nodes_deleted
relationships_upserted
relationships_deleted
affected_file_paths
affected_symbol_ids
```

Backend rules:

- for `full_snapshot`, replace the active graph projection for the repository snapshot scope;
- for `affected_subgraph`, upsert affected nodes and relationships, then mark removed nodes inactive;
- keep previous snapshot data queryable for audit until retention policy removes it;
- link graph changes to `delta_sync_id` and `run_id`.

## Wiki Updates

Delta Sync can write wiki revisions directly.

Required behavior:

- create new revisions for updated pages;
- mark previous revisions stale when their evidence is superseded;
- mark pages `conflict_with_code` when manual content contradicts verified analyzer output;
- mark pages `needs_verification` when required evidence was excluded or analysis failed;
- include evidence refs for every generated technical section.

The plugin may also send `affected_wiki_pages` without content when it can identify stale pages but cannot regenerate them safely.

## Risk Report

Risk triggers:

```text
auth_touched
payment_touched
security_touched
privacy_touched
migrations_changed
destructive_operation_detected
test_failures
high_uncertainty
large_multi_file_diff
protected_paths_touched
low_confidence_model_output
secret_scan_warning
secret_scan_block
```

Risk calculation rules:

- any hard-blocked secret sets risk to `critical` and prevents finalize;
- migrations changed sets minimum risk to `high`;
- test failures set minimum risk to `high`;
- protected paths touched sets minimum risk to `high`;
- large multi-file diff sets minimum risk to `medium`;
- low-confidence analyzer output sets minimum risk to `medium`;
- no triggers and passing tests may be `low`.

## Backend Validation

Finalize must validate:

- active or known base snapshot;
- repository policy version used by plugin;
- changed file paths remain inside repository scope;
- blocked files are not uploaded;
- artifact hashes;
- chunk completeness;
- graph schema;
- wiki evidence shape;
- risk report schema.

Failure handling:

- invalid base snapshot returns `schema_validation_failed`;
- blocked secret content returns `secret_scan_blocked`;
- missing artifact chunks return `artifact_chunk_missing`;
- mismatch hashes return `artifact_hash_mismatch`;
- graph import failure marks Delta Sync failed and leaves previous active snapshot unchanged.

## Snapshot Rules

Delta Sync creates a new snapshot.

Rules:

- historical snapshots are immutable;
- the new snapshot references the base snapshot;
- old evidence becomes stale only after successful finalize;
- dashboard must label the new snapshot as local plugin state;
- V1 does not mark branches pushed, PR opened, or merged.

## Acceptance Criteria

Delta Sync is accepted when:

- the plugin can compare local changes against an active Genesis snapshot;
- changed files and hashes are reported;
- affected symbols and relations are recomputed;
- safety policy blocks hard secrets and warns on excluded content;
- artifacts upload via manifest/chunk/finalize;
- backend creates a new snapshot;
- Neo4j reflects the changed graph projection;
- wiki pages are updated or marked stale with evidence;
- run detail shows diff summary, risk report, tests, artifacts, and graph update status.

