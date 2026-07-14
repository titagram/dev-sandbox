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
`stale`. The public `graph_version` is derived from the canonical artifact
identity and checksum. `active_graph_version` selects the verified physical
Neo4j copy without changing that public artifact identity.

Queue workers and forced synchronous rebuilds use one durable publication
protocol in `canonical_graph_projection_attempts`; `kind=ordinary` and
`kind=forced` describe the caller, not separate state machines. Reconciliation
inserts missing rows or retries final `failed` rows but does not steal healthy
`queued` or `projecting` work. Every owner projects a distinct physical
candidate, verifies node, relationship, and adjacency counts, persists
`publication_stage=marker_pending`, publishes the Neo4j current/capability
marker, and only then advances PostgreSQL to `ready` with the new
`active_graph_version`. The stable project lock and compare-and-swap base
pointer serialize ordinary/forced interleavings. A losing attempt is
superseded and its candidate is cleaned; it cannot overwrite the winner.

The publication lease is **900 seconds** and every acquisition, comparison,
heartbeat, and expiry decision uses PostgreSQL `clock_timestamp()` rather than
an application-host clock or transaction-fixed `CURRENT_TIMESTAMP`. Ownership
is live strictly before `lease_expires_at`; at the exact timestamp it is
expired. Projection heartbeats renew the full
900-second lease after each bounded Neo4j phase. The longest declared Neo4j
wait is `db.awaitIndexes(300)` and queue workers are killed at 600 seconds in
both development and production Compose, leaving a 300-second reclaim margin.
Do not increase either timeout beyond the lease or reduce the lease without
re-running the just-before/at/after-expiry and wrong-owner heartbeat tests.

If a process dies before the marker, recovery restores Neo4j to PostgreSQL's
active pointer, deletes the partial candidate, and makes an ordinary delivery
claimable again. If it dies after the marker but before the PostgreSQL pointer,
the same recovery first restores the PostgreSQL source-of-truth marker, then
cleans the abandoned candidate and retries. The verified projection remains
queryable throughout a forced attempt. A failed attempt records only a bounded
failure code; it never silently publishes PostgreSQL without the Neo4j marker.
Persisted failure codes never contain raw exception text.

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

### Bounded traversal rollout

Canonical traversal schema version 1 materializes two
`CanonicalGraphAdjacency` lookup nodes for every relationship (`in` and
`out`). Their deterministic `direction_rank` and `any_rank` values are indexed
with graph version and source node. A traversal therefore applies its
per-frontier rank bound in a Neo4j range index seek before loading target
nodes; it never performs an unbounded relationship expansion.

Projections published before this schema do not have adjacency lookup nodes or
the `CanonicalGraphVersion.traversal_schema_version = 1` capability marker.
The backend deliberately returns `graph_projection_rebuild_required` for
traversal against those versions. It does not silently fall back to the legacy
unbounded query. Callers, callees, and shortest-path reads retain their existing
behavior while the traversal rebuild is pending.

Roll out the feature in this order: enter the approved maintenance window,
drain and stop queue producers/consumers, verify a backup, apply the additive
database migration, deploy the schema-dependent application code, then
force-rebuild each selected canonical scope. A forced rebuild writes a
distinct candidate physical graph version while PostgreSQL continues pointing
at the previously published version. Only after node, relationship, and
adjacency counts all verify does one final Neo4j statement mark the candidate
current and set its traversal schema marker; only after that does PostgreSQL
publish the candidate as `active_graph_version`. A failed or partial candidate
is never queried and leaves the previous published projection unchanged.

After every scope is rebuilt, verify the marker and adjacency count with
read-only Cypher, then smoke traversal. The adjacency count must be exactly
twice the relationship count for the same active physical graph version:

```cypher
MATCH (v:CanonicalGraphVersion {graph_version: $active_graph_version})
OPTIONAL MATCH ()-[r {graph_version: $active_graph_version}]->()
WITH v, count(r) AS relationships
OPTIONAL MATCH (a:CanonicalGraphAdjacency {graph_version: $active_graph_version})
RETURN v.current AS current,
       v.traversal_schema_version AS traversal_schema_version,
       relationships,
       count(a) AS adjacencies;
```

Expected: `current = true`, `traversal_schema_version = 1`, and
`adjacencies = relationships * 2`. Stop the rollout if any invariant differs.

The historical command without `--reconcile` remains available for legacy
snapshot artifacts and accepts `--repository`, `--snapshot`, and `--mode`.
This path performs a forceful rebuild rather than a read-only preview, even when
`--mode=fake`; it has no `--dry-run` and requires the same backup, verification,
test, and explicit human gate as any non-dry canonical reconciliation. Those
options are incompatible with canonical reconciliation. This preserves Genesis
events and legacy behavior while canonical readers share the new source-scoped
service.

## Migration, backup, and verification order

The order below is mandatory. PostgreSQL is the source of truth for the active
physical graph pointer; Neo4j is reconciled to it after an interrupted
publication. Do not start schema-dependent application code before the
additive migration succeeds. Do not resume queue or scheduler services before
all marker/count and HTTP smoke checks pass.

1. **Enter maintenance and drain every queue producer.** Put the application
   in maintenance first, stop the scheduler, allow the existing worker to
   finish the jobs already in `jobs`, and only then stop the worker. If the
   queue does not reach zero, stop and investigate; do not kill a healthy
   projection while it owns a lease.

   ```bash
   docker compose -f docker-compose.devboard.yaml \
     -f docker-compose.devboard.traefik.yaml exec -T app php artisan down
   docker compose -f docker-compose.devboard.yaml \
     -f docker-compose.devboard.traefik.yaml stop scheduler
   docker compose -f docker-compose.devboard.yaml exec -T postgres \
     psql -U devboard -d devboard -Atc 'SELECT count(*) FROM jobs;'
   # Repeat the read-only count until it is 0.
   docker compose -f docker-compose.devboard.yaml \
     -f docker-compose.devboard.traefik.yaml stop worker
   ```

2. **Create and verify the PostgreSQL backup.** Store it outside the mutable
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

3. **Pass the test and formatting gates.** Run the selected SQLite suites, the
   canonical graph tests, Pint, and the read-only migration status command.

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app sh -lc \
     'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades tests/Feature/CanonicalGraphRepositoryTest.php tests/Feature/CanonicalGraphRebuildCommandTest.php'
   docker compose -f docker-compose.devboard.yaml exec -T app \
     vendor/bin/pint --test
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan migrate:status
   ```

4. **Run the read-only canonical preview.** Select the exact project and,
   optionally, the exact scope. Stop on any nonzero `failed` count.

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan devboard:neo4j-rebuild --reconcile \
       --project=<project_uuid> --dry-run
   ```

5. **Obtain explicit human authorization.** Present the maintenance/drain
   evidence, backup path, successful
   `pg_restore -l` result, recorded counts, test/Pint results, migration status,
   dry-run JSON, exact mutating commands, and affected project/scope. Stop here
   until a human explicitly authorizes those commands.

6. **Apply the required additive migration before schema-dependent code is
   started.** Skip this command only if `migrate:status` proves it was already
   applied. Keep maintenance active and worker/scheduler stopped.

   ```bash
   docker compose -f docker-compose.devboard.yaml exec -T app \
     php artisan migrate --force
   ```

7. **Deploy with Traefik, but keep background services stopped.** Rebuild and
   start only the request-serving services needed for validation. Never use the
   base Compose file alone for an app recreation, and do not start `worker` or
   `scheduler` yet.

   ```bash
   docker compose -f docker-compose.devboard.yaml \
     -f docker-compose.devboard.traefik.yaml up -d --build app frontend
   ```

   Preserve `traefik_default`, router priorities, redirect and Basic Auth
   middleware, and the distinct frontend/API/Hades/plugin routes.

8. **Run exactly one authorized graph mutation.** Use canonical reconciliation
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

9. **Verify PostgreSQL, Neo4j, and the routed application before resuming.**
   Confirm exactly one `ready` projection per selected source and that its
   `active_graph_version` equals the Neo4j node marked `current=true`. Run the
   read-only Cypher marker/adjacency query above; require
   `traversal_schema_version=1` and exactly two adjacency rows per relationship.
   Compare Hades and plugin reads: both must report the backend-selected graph
   version for the same source. Smoke the root without credentials (Basic Auth
   challenge) and with credentials, the login flow, Hades health/auth, and a
   plugin endpoint. Confirm there is no authentication 401 regression.

10. **Resume only after every check passes.** Start worker and scheduler, leave
    maintenance, and verify both background services become healthy.

    ```bash
    docker compose -f docker-compose.devboard.yaml \
      -f docker-compose.devboard.traefik.yaml up -d worker scheduler
    docker compose -f docker-compose.devboard.yaml \
      -f docker-compose.devboard.traefik.yaml exec -T app php artisan up
    docker compose -f docker-compose.devboard.yaml \
      -f docker-compose.devboard.traefik.yaml ps
    ```

### Interrupted publication and rollback

If a process dies in `projecting`, its bounded lease eventually expires. The
next forced rebuild for that scope takes the project lock, restores Neo4j's
`current` marker from PostgreSQL `active_graph_version`, marks the dead attempt
`abandoned`, and idempotently removes its non-current candidate. A healthy
attempt renews its lease between projection batches and is never reclaimed.

If a process dies after changing the Neo4j marker but before committing the
PostgreSQL pointer, the persisted `publishing/marker_pending` stage makes the
same reclaimer restore the PostgreSQL winner before removing the candidate.
Never manually delete a PostgreSQL-active or Neo4j-current version; reconcile
the marker first under the project lock.

For application rollback after migration, keep maintenance active and
worker/scheduler stopped, restore the previous code, and leave the additive
columns/table in place unless an explicitly tested rollback requires otherwise.
For data loss or a destructive/reset operation, restore the verified dump,
then rebuild Neo4j from canonical artifacts. After any database restore, rerun
the required development user seeder before declaring the environment usable.

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
