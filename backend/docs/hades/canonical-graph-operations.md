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
`no_relationships_extracted`, `bounded_or_omitted_input`, or
`canonicalization_omissions`; the analyzer may
report `graphify_unavailable` or `graphify_failed:<ExceptionClass>`. It never
serializes a raw exception message or an absolute workspace path. Trusted
pre-contract rows are adapted at read time with `mode=legacy_adapter`,
`quality=partial`, and `fallback_reason=missing_contract_metadata`; stored
payloads are not rewritten.

When `graph_contract` is present, it is explicit canonical input and the
backend validates it before creating or running a projection. The four top
level keys above are required. `extractor` must contain exactly `name`,
`version`, `mode`, `quality`, and nullable `fallback_reason`; `coverage` must
contain exactly `languages`, `files_total`, `files_analyzed`, and
`files_failed`; `source` must contain exactly nullable `branch` and
`head_commit`. Extractor names (64 characters), versions (32), languages (up
to 16 entries of 32), branches (255), commits (80 hexadecimal characters),
and fallback codes (100) are bounded and grammar checked. Coverage is a
partition: `files_total = files_analyzed + files_failed`. `full` quality
requires a null fallback; degraded quality requires a bounded code. `mode` is
one of `native`, `graphify`, `fallback`, or `legacy_adapter`. An explicit malformed contract is rejected;
it is never silently downgraded to the legacy adapter.

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
scope is selected. The selected preview is data-minimized: raw/private
identifiers, labels, source refs, local paths, and raw edge endpoints are never
returned. Nodes may contain schema-approved safe presentation labels; node ids
and returned edge endpoints are deterministic pseudonyms, so node-edge
references remain coherent without exposing canonical identities. Preview
aliases are presentation values, not canonical graph ids.

## Projection lifecycle and concurrency

Projection rows move through `queued`, `projecting`, `ready`, `failed`, and
`stale`. Each `graph_version` is derived from the canonical artifact identity
and checksum and scopes every Neo4j node and relationship.

Queue workers claim `queued` rows conditionally. Reconciliation inserts missing
rows or retries final `failed` rows but does not steal `queued` or `projecting`
work. A forced synchronous rebuild can claim only an inactive row with the same
project, scope, artifact, checksum, and version. That claimed row can move from
`ready` to `projecting` and then to `failed` if verification fails. Neo4j keeps
the previously verified current marker queryable during that failure; operators
must retry or reconcile the failed PostgreSQL lifecycle row. A successful
replacement is marked `ready` only after projected node and relationship counts
verify atomically; then the previous `ready` row in that scope becomes `stale`.
Persisted failure codes are bounded and never contain raw exception text.

## Reconcile and rebuild

The canonical command always requires `--reconcile` and `--project`. Before the
human gate, only its bounded read-only preview may be run:

```bash
php artisan devboard:neo4j-rebuild --reconcile --project=<project_uuid> --dry-run
```

Optionally restrict the operation to one exact source. `--scope-type` and
`--scope-id` must be supplied together:

```bash
php artisan devboard:neo4j-rebuild --reconcile --project=<project_uuid> \
  --scope-type=workspace_binding --scope-id=<binding_uuid> --dry-run
```

The non-dry syntax is intentionally not executable in this pre-gate section:
`devboard:neo4j-rebuild --reconcile --project PROJECT [--scope-type TYPE
--scope-id ID]`. Its real invocation appears only in the authorized procedure
below.

The JSON summary contains `scanned`, `queued`, `ready`, `failed`, `skipped`,
and `dry_run`. Dry run performs bounded reads only: it neither creates
projection rows nor dispatches jobs nor writes Neo4j. A real reconciliation
queues the latest canonical artifact for each selected source; process the
Laravel queue before expecting it to become `ready`.

The historical command without `--reconcile` remains available for legacy
snapshot artifacts and accepts `--repository`, `--snapshot`, and `--mode`.
This path performs a forceful rebuild rather than a read-only preview, even when
`--mode=fake`; it has no `--dry-run` and requires the same backup, verification,
test, and explicit human gate as any non-dry canonical reconciliation. Those
options are incompatible with canonical reconciliation. This preserves Genesis
events and legacy behavior while canonical readers share the new source-scoped
service.

## Migration, backup, and verification order

The order below is mandatory. No migration, deployment, non-dry canonical
reconciliation, or legacy rebuild command may be copied or run before steps
1-4 are complete.

1. **Create and verify the PostgreSQL backup.** Store it outside the mutable
   application data path. Listing the archive with `pg_restore -l` is required;
   file existence alone is not verification.

   ```bash
   mkdir -p /home/ubuntu/backups/devboard
   BACKUP=/home/ubuntu/backups/devboard/devboard-before-canonical-graph-$(date -u +%Y%m%dT%H%M%SZ).dump
   docker compose -f docker-compose.devboard.yaml exec -T postgres \
     pg_dump -U devboard -d devboard -Fc > "$BACKUP"
   test -s "$BACKUP"
   docker compose -f docker-compose.devboard.yaml exec -T postgres \
     pg_restore -l < "$BACKUP" >/dev/null
   printf '%s\n' "$BACKUP"
   ```

   Record users, projects, canonical artifacts, and projection row counts with
   read-only SQL before continuing.

2. **Pass the test and formatting gates.** Run the selected SQLite suites, the
   canonical graph tests, Pint, and the read-only migration status command.

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app sh -lc \
     'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades tests/Feature/CanonicalGraphRepositoryTest.php tests/Feature/CanonicalGraphRebuildCommandTest.php'
   docker compose -f docker-compose.devboard.yaml exec -T app \
     vendor/bin/pint --test
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan migrate:status
   ```

3. **Run the read-only canonical preview.** Select the exact project and,
   optionally, the exact scope. Stop on any nonzero `failed` count.

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan devboard:neo4j-rebuild --reconcile \
       --project=<project_uuid> --dry-run
   ```

4. **Obtain explicit human authorization.** Present the backup path, successful
   `pg_restore -l` result, recorded counts, test/Pint results, migration status,
   dry-run JSON, exact mutating commands, and affected project/scope. Stop here
   until a human explicitly authorizes those commands.

5. **Only after authorization, apply the required additive migration.** Skip
   this command if `migrate:status` showed nothing pending.

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan migrate --force
   ```

6. **Run exactly one authorized graph mutation.** Use canonical reconciliation
   for canonical sources:

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan devboard:neo4j-rebuild --reconcile \
       --project=<project_uuid>
   ```

   To restrict it, append both `--scope-type=workspace_binding
   --scope-id=<binding_uuid>` or both `--scope-type=repository
   --scope-id=<repository_uuid>`. For an explicitly authorized legacy rebuild,
   use the historical forceful path instead:

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan devboard:neo4j-rebuild \
       --repository=<repository_uuid> --snapshot=<snapshot_uuid> --mode=fake
   ```

7. **Drain the queue and verify.** Confirm one `ready` projection per selected
   source with expected nonzero counts. Compare Hades and plugin reads: both
   must report the backend-selected graph version for the same source. Confirm
   there is no authentication 401 regression.

8. **Deploy only if separately authorized, preserving Traefik.** Never use the
   base Compose file alone for an app recreation.

   ```bash
   docker compose -f docker-compose.devboard.yaml \
     -f docker-compose.devboard.traefik.yaml up -d --build
   ```

   Preserve `traefik_default`, router priorities, redirect and Basic Auth
   middleware, and the distinct frontend/API/Hades/plugin routes. Smoke the
   root without credentials (Basic Auth challenge) and with credentials, then
   the login flow, Hades health/auth, and a plugin endpoint.

Do not restore the backup after a successful additive migration. Restore is an
authorized rollback only if a destructive/reset operation or data loss occurs;
after a restore, rerun the required development user seeder before declaring
the environment usable. Neo4j can be dropped and reconstructed from canonical
artifacts, but PostgreSQL artifacts and projection lifecycle state must be
protected.

When the host requires an architecture override, add it after the two required
Compose files. The Traefik override is never optional.

The React frontend cutover and complete removal of Inertia are a separate
delivery tranche. They are not implemented or deployed by this runbook.
