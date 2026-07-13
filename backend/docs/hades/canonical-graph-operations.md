# Canonical Graph Operations

This runbook covers the canonical graph foundation only. Canonical artifacts in
PostgreSQL/object storage are authoritative; Neo4j is an isolated, rebuildable
projection.

## Artifact contract

New Hades and analyzer graph artifacts add `graph_contract` without replacing
their existing schema or response fields. `hades.php_graph.v1` and
`hades.code_graph.v1` remain valid Hades schemas. The contract version is
`hades.graph_artifact.v1`:

```json
{
  "version": "hades.graph_artifact.v1",
  "extractor": {
    "name": "hades-native-php",
    "version": "1",
    "mode": "native",
    "quality": "full",
    "fallback_reason": null
  },
  "coverage": {
    "languages": ["php"],
    "files_total": 10,
    "files_analyzed": 10,
    "files_failed": 0
  },
  "source": {
    "branch": "main",
    "head_commit": "<commit>"
  }
}
```

`quality` is `full`, `partial`, or `inventory_only`. Fallback reasons are
bounded machine values: native Hades may report
`no_relationships_extracted` or `bounded_or_omitted_input`; the analyzer may
report `graphify_unavailable` or `graphify_failed:<ExceptionClass>`. It never
serializes a raw exception message or an absolute workspace path. Trusted
pre-contract rows are adapted at read time with `mode=legacy_adapter`,
`quality=partial`, and `fallback_reason=missing_contract_metadata`; stored
payloads are not rewritten.

## Source resolution and read compatibility

Every canonical lookup uses `project_id` and exactly one source scope:

- `workspace_binding` plus the linked Hades binding id; or
- `repository` plus the repository id.

The repository never falls back across scope types. Clients can select a scope
but cannot provide `graph_version`. The backend selects the most recent
verified `ready` projection for that exact source. When it does not exist,
canonical reads return `graph_projection_not_ready` rather than an empty graph.
The legacy graph compatibility service retains its historical
`graph_snapshot_not_found` reason.

Plugin and Hades envelopes keep all legacy fields. Canonical reads add source
scope, `projection_id`, `artifact_id`, `schema`, `graph_version`,
`head_commit`, `quality`, node/relationship counts, edges, and provenance as
appropriate to the endpoint. Additive fields must not become new required
request fields.

Dashboard multi-scope responses are bounded and expose only metadata until a
scope is selected. The preview is data-minimized: identifiers are deterministic
pseudonyms, node-edge references remain coherent, and local paths or
path-derived identifiers, labels, source refs, and edge endpoints are not
returned. Preview aliases are presentation values, not canonical graph ids.

## Projection lifecycle and concurrency

Projection rows move through `queued`, `projecting`, `ready`, `failed`, and
`stale`. Each `graph_version` is derived from the canonical artifact identity
and checksum and scopes every Neo4j node and relationship.

Queue workers claim `queued` rows conditionally. Reconciliation inserts missing
rows or retries final `failed` rows but does not steal `queued` or `projecting`
work. A forced synchronous rebuild can claim only an inactive row with the same
project, scope, artifact, checksum, and version. The replacement is marked
`ready` only after projected node and relationship counts verify atomically;
then the previous `ready` row in that scope becomes `stale`. If projection
fails, the previous verified projection stays current. Persisted failure codes
are bounded and never contain raw exception text.

## Reconcile and rebuild

The canonical command always requires `--reconcile` and `--project`:

```bash
php artisan devboard:neo4j-rebuild --reconcile --project=<project_uuid> --dry-run
php artisan devboard:neo4j-rebuild --reconcile --project=<project_uuid>
```

Optionally restrict the operation to one exact source. `--scope-type` and
`--scope-id` must be supplied together:

```bash
php artisan devboard:neo4j-rebuild --reconcile --project=<project_uuid> \
  --scope-type=workspace_binding --scope-id=<binding_uuid> --dry-run

php artisan devboard:neo4j-rebuild --reconcile --project=<project_uuid> \
  --scope-type=repository --scope-id=<repository_uuid>
```

The JSON summary contains `scanned`, `queued`, `ready`, `failed`, `skipped`,
and `dry_run`. Dry run performs bounded reads only: it neither creates
projection rows nor dispatches jobs nor writes Neo4j. A real reconciliation
queues the latest canonical artifact for each selected source; process the
Laravel queue before expecting it to become `ready`.

The historical command without `--reconcile` remains available for legacy
snapshot artifacts and accepts `--repository`, `--snapshot`, and `--mode`.
Those options are incompatible with canonical reconciliation. This preserves
Genesis events and legacy behavior while canonical readers share the new
source-scoped service.

## Migration, backup, and verification order

Before any live migration or non-dry reconciliation:

1. Create a PostgreSQL backup outside the application host's mutable data path.
2. Verify the archive can be listed/restored and record users, projects,
   canonical artifacts, and projection row counts.
3. Run the SQLite test suite and Pint, then inspect `php artisan migrate:status`.
4. Apply the additive migration with explicit human authorization.
5. Run project-wide or scoped `--dry-run`; stop on a nonzero `failed` count.
6. Run the matching non-dry command, drain the queue, and verify one `ready`
   projection per selected source with nonzero expected counts.
7. Compare Hades and plugin reads: both must report the backend-selected graph
   version for the same source. Confirm no authentication 401 regression.

Do not restore the backup after a successful additive migration. Restore is an
authorized rollback only if a destructive/reset operation or data loss occurs;
after a restore, rerun the required development user seeder before declaring
the environment usable. Neo4j can be dropped and reconstructed from canonical
artifacts, but PostgreSQL artifacts and projection lifecycle state must be
protected.

The React frontend cutover and complete removal of Inertia are a separate
delivery tranche. They are not implemented or deployed by this runbook.
