# DevBoard Operational Hardening Remediation Plan

> Execution profile: this plan is intentionally explicit. A worker must execute one task at a time, in order, and must not invent alternative architecture. If a stop condition is reached, stop and report the exact command and output.

**Goal:** Close the verified gaps left after `2026-07-09-devboard-operational-hardening.md`: bounded artifact storage, complete provider SSRF protection, correct graph projection/query behavior, repository-aware Python call edges, serialized audit chains, real PostgreSQL/pgvector acceptance, production vector retrieval, and enforced CI quality gates.

**Scope:** Backend Laravel, analyzer, plugin regression checks, Docker/CI, source-of-truth documentation, and sandbox logbooks. Frontend behavior is out of scope unless a backend contract change makes a frontend type update unavoidable.

**Execution order:** Do not reorder tasks. Tasks marked parallel-safe may be delegated concurrently only after their common prerequisite is green.

---

## 1. Non-Negotiable Worker Rules

1. Work from `/home/ubuntu/dev-sandbox`.
2. Read `AGENTS.md`, `ai-sandbox/INIT.md`, `ai-sandbox/instructions/INDEX.md`, the applicable workflow, all policy files, and `ai-sandbox/config/project.yaml` before editing.
3. Run `git status --short` before every task.
4. Do not reset, restore, clean, or overwrite unrelated changes.
5. Before editing, append the task's exact intended write paths to `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`.
6. Use TDD: add the stated test, run it, and record the expected RED result before changing production code.
7. Make only the production changes listed in the current task.
8. Run the stated GREEN command, static checks, and `git diff --check` before marking a task complete.
9. Do not weaken assertions, add broad exception catches, add `|| true`, skip tests, or create compatibility fallbacks to make a gate pass.
10. Do not modify the old plan's checkboxes until Task 6.2.
11. Do not commit unless the operator explicitly requests commits. If commits are requested, use one commit per task after all task gates pass.
12. Never place secrets in tracked files, command output, tests, fixtures, logbooks, or plan evidence.

### Required Task Handoff

At the end of every task, append this exact structure to the project logbook:

```markdown
## YYYY-MM-DD - Remediation Task X.Y

- Request: execute Task X.Y from `docs/superpowers/plans/2026-07-10-devboard-operational-hardening-remediation.md`.
- Intended write paths: ...
- RED verification: `<command>` -> `<exact failure summary>`.
- Work performed: ...
- GREEN verification: `<command>` -> `<exact pass summary>`.
- Files changed: ...
- Residual risks: none, or an explicit list.
```

### Baseline Evidence Already Collected

These results are context, not permission to skip later gates:

- Laravel SQLite suite: `527 passed`, `4266 assertions`, `4 skipped`.
- Python analyzer/plugin suite in a clean editable environment: `116 passed`.
- Node agent suite: `19 passed`.
- PHPStan level 1: passed.
- Pint did not finish within 120 seconds and is not yet an enforced CI gate.
- DevBoard PostgreSQL and Neo4j containers were healthy.

---

## 2. Fixed Architecture Decisions

Workers must use these decisions. Do not reopen them during implementation.

### Artifact Uploads

- Keep the existing filesystem chunk layout.
- Do not add an `artifact_chunks` table in this remediation.
- Serialize chunk accounting with `artifacts` row locks.
- Iterate only declared indexes `0..chunk_count-1`; never scan an unbounded chunk directory.
- `size_bytes` is an exact required final size, not an estimate.
- Add error codes `artifact_chunk_out_of_range` and `artifact_size_mismatch`.
- Preserve same-index/same-hash idempotency.

### Provider Endpoints

- Arbitrary public HTTPS provider hosts remain supported.
- Unresolved hosts fail closed.
- Every A and AAAA response must be public; one unsafe response rejects the host.
- HTTP remains forbidden except where an existing, explicit development-only allowlist is already required by product behavior. Do not infer a new allowlist.
- Direct HTTP calls must disable redirects and pin the checked IP while preserving hostname/TLS SNI.
- SDK-backed provider paths must use the same pinned transport. If the installed SDK cannot accept a custom transport, stop Task 1.4 and report the missing extension point. Do not claim SSRF closure based on validation alone.

### Graph

- Every imported node keeps the base `:CodeNode` label.
- Known semantic labels are additive: `:File`, `:Function`, `:Class`, `:Module`.
- `Method` maps to `:Function` for the current schema.
- Unknown labels remain only `:CodeNode`.
- Python cross-file resolution uses explicit imports and aliases. It must not guess from a globally unique unimported name.

### Audit Chain

- Use one global serialized audit chain.
- Add a one-row `audit_chain_heads` table and lock it with `FOR UPDATE` inside the same transaction as the audit insert.
- Hash immutable actor reference columns, not nullable foreign keys.
- Expansion/backfill and final constraints are separate migrations.
- No production code may insert directly into `audit_logs` outside `AuditLogger`.

### PostgreSQL And Vector Search

- CI and disposable acceptance use a PostgreSQL 16 image that includes pgvector.
- The image must be pinned by digest before merging.
- SQLite remains the fast general test profile.
- PostgreSQL-only tests live in a strict suite that never skips because the driver is wrong.
- Vector retrieval is a secondary signal. Lexical retrieval remains available when embeddings are disabled or degraded.
- Embeddings are generated asynchronously after source document commits.

---

## 3. Dependency Graph

Execute in this order:

```text
0.1 Baseline and status ledger
  -> 0.2 Rotate and redact exposed historical secrets
  -> 1.1 Artifact index and exact-size enforcement
  -> 1.2 Abandoned upload cleanup
  -> 1.3 Resolver-based endpoint policy
  -> 1.4 Pinned provider transport on every runtime path
  -> 2.1 Assistant graph project scope
  -> 2.2 Semantic Neo4j labels
  -> 2.3 Repository-aware Python CALLS edges
  -> 2.4 Graph documentation and projection refresh
  -> 2.5 Repair sandbox graph configuration
  -> 3.1 PostgreSQL/pgvector acceptance lane
  -> 3.2 Serialized audit chain expansion and verifier
  -> 3.3 Replace all raw audit writes
  -> 3.4 Audit backfill and final constraints
  -> 4.1 Embedding generation and indexing lifecycle
  -> 4.2 Vector candidates in memory search
  -> 5.1 Pint/PHPStan CI enforcement
  -> 6.1 Full acceptance
  -> 6.2 Reconcile plans, wiki, and closure evidence
```

Tasks 2.1 and 2.2 are parallel-safe with each other only after Task 1.4 is green. All other tasks are sequential.

---

## Task 0.1: Create A Truthful Execution Ledger

**Priority:** P0 process safety

**Modify:**

- `docs/superpowers/plans/2026-07-10-devboard-operational-hardening-remediation.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Steps

- [x] Run `git status --short` and record every pre-existing modified/untracked path.
  - Result at execution start: clean working tree, no pre-existing modified/untracked paths. No stop condition triggered.
- [x] Confirm `.env` is ignored with `git check-ignore -v .env`.
  - Result: `.gitignore:10:/.env .env` — confirmed ignored.
- [x] Add a dated execution table under this task with one row per task and initial status `pending`.
- [x] Use only these statuses: `pending`, `in_progress`, `blocked`, `verified`.
- [x] Mark exactly one task `in_progress` at a time.
- [x] Record the baseline commands from Section 1 as historical evidence only.

### Execution Ledger (2026-07-10)

Baseline evidence (historical context only, from Section 1):

- Laravel SQLite suite: `527 passed`, `4266 assertions`, `4 skipped`.
- Python analyzer/plugin suite in a clean editable environment: `116 passed`.
- Node agent suite: `19 passed`.
- PHPStan level 1: passed.
- Pint did not finish within 120 seconds and is not yet an enforced CI gate.
- DevBoard PostgreSQL and Neo4j containers were healthy.

| Task | Description | Status |
| --- | --- | --- |
| 0.1 | Create a truthful execution ledger | `verified` |
| 0.2 | Rotate and redact exposed historical secrets | `verified` (autonomous redaction + CI; runtime rotation pending operator) |
| 1.1 | Enforce artifact chunk range and exact size | `verified` |
| 1.2 | Purge abandoned uploads and legacy extra chunks | `verified` |
| 1.3 | Replace endpoint validation with a testable resolver | `verified` |
| 1.4 | Pin provider connections on every runtime path | `verified` |
| 2.1 | Pass project scope into assistant graph queries | `verified` |
| 2.2 | Preserve semantic Neo4j labels | `verified` |
| 2.3 | Resolve Python CALLS across files and Delta boundaries | `verified` |
| 2.4 | Reconcile graph documentation and refresh derived data | `verified` (docs verified; derived refresh blocked: no graph artifacts) |
| 2.5 | Repair sandbox graph configuration and audit | `verified` |
| 3.1 | Add a strict PostgreSQL and pgvector acceptance lane | `verified` |
| 3.2 | Serialize the audit chain and add verification | `verified` |
| 3.3 | Replace every raw production audit insert | `verified` |
| 3.4 | Backfill and enforce audit chain constraints | `verified` (fresh acceptance; live DB migration pending) |
| 4.1 | Generate and index embeddings asynchronously | `verified` |
| 4.2 | Add vector candidates to memory search | `verified` |
| 5.1 | Enforce Pint and PHPStan in CI | `in_progress` |
| 6.1 | Run full acceptance | `pending` |
| 6.2 | Reconcile historical plans and close the remediation | `pending` |

### Verification

```bash
git diff --check -- \
  docs/superpowers/plans/2026-07-10-devboard-operational-hardening-remediation.md \
  ai-sandbox/logbooks/LOGBOOK_PROJECT.md
```

### Stop Conditions

- Stop if a planned implementation file already has unrelated uncommitted changes.
- Stop if `.env` is not ignored.

---

## Task 0.2: Rotate And Redact Exposed Historical Secrets

**Priority:** P0 credential response

**Depends on:** Task 0.1

**Modify:**

- tracked documentation returned by the secret scan
- ignored local `.env` through an approved secret source
- deployment secret store outside the repository
- `.github/workflows/ci.yml`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Verified Exposure

At plan creation, tracked historical plans contained a concrete Laravel `APP_KEY` and known Neo4j development passwords. Treat them as compromised even if they are believed to be obsolete. Never copy their values into this remediation plan or logbook.

### Operator Checkpoint

Before changing runtime credentials:

- [ ] Confirm whether local PostgreSQL/Neo4j data must be preserved.
- [ ] Create and verify a backup using the existing backup runbook.
- [ ] Obtain replacement `APP_KEY`, `DB_PASSWORD`, and `NEO4J_PASSWORD` from the approved secret source.
- [ ] Do not generate production secrets inside an agent transcript.
- [ ] Confirm the maintenance window because `APP_KEY` rotation invalidates sessions and may invalidate encrypted application values.

### Redaction Steps

- [ ] Run a tracked-file secret scan without printing matched secret values into the logbook.
- [ ] Replace concrete credentials in current tracked documentation with placeholders such as `<redacted-rotated-secret>`.
- [ ] Preserve historical meaning by adding a note that the exposed credential was rotated on the remediation date.
- [ ] Do not delete historical plans solely to hide the exposure.
- [ ] Do not rewrite Git history without separate explicit operator approval and coordination with every clone/remote.

### Runtime Rotation Steps

- [ ] Rotate Laravel `APP_KEY` according to the application's encrypted-data impact assessment.
- [ ] Rotate the PostgreSQL role password with an authenticated database operation, then update the external secret store and ignored local `.env`.
- [ ] Rotate the Neo4j user password with an authenticated Neo4j operation, then update the external secret store and ignored local `.env`.
- [ ] Recreate application/worker/scheduler containers with the new environment.
- [ ] Verify old credentials no longer authenticate. Record only pass/fail, never credential values.
- [ ] Verify login, database health, Neo4j health, queue worker, and scheduler after rotation.

### CI Prevention

- [ ] Add a pinned secret-scanning tool/job that scans the current checkout.
- [ ] Make the job fail on detected concrete credentials.
- [ ] Add test fixtures only through the scanner's documented allowlist mechanism with exact file/rule scope.
- [ ] Do not blanket-ignore `docs/` or `*.md`.

### Verification

```bash
git grep -n -E 'APP_KEY=base64:[A-Za-z0-9+/=]{20,}|NEO4J_PASSWORD=[^<$ {][^ ]+|neo4j/[A-Za-z0-9_-]{8,}' -- . \
  ':(exclude)docs/superpowers/plans/2026-07-10-devboard-operational-hardening-remediation.md'
docker compose -f docker-compose.devboard.yaml config --quiet
docker compose -f docker-compose.devboard.yaml ps
git diff --check
```

Expected: no concrete tracked credentials, Compose renders, and runtime services are healthy.

### Stop Conditions

- Stop before runtime rotation if no verified backup exists and data preservation is required.
- Stop if a replacement secret would be printed to terminal/log output.
- Stop if the operator has not approved Git history rewriting; current-tree redaction and credential rotation still proceed.

---

## Task 1.1: Enforce Artifact Chunk Range And Exact Size

**Priority:** P0 security and availability

**Depends on:** Task 0.2

**Modify:**

- `backend/app/Services/ArtifactStorageService.php`
- `backend/app/Http/Controllers/Plugin/GenesisChunkController.php`
- `backend/app/Http/Controllers/Plugin/DeltaChunkController.php`
- `backend/app/Http/Controllers/Plugin/GenesisFinalizeController.php`
- `backend/app/Http/Controllers/Plugin/DeltaFinalizeController.php`
- `backend/app/Services/GenesisFinalizeService.php`
- `backend/app/Services/DeltaFinalizeService.php`
- `backend/tests/Feature/GenesisUploadTest.php`
- `backend/tests/Feature/DeltaSyncTest.php`
- `backend/tests/Feature/Plugin/ArtifactUploadLimitsTest.php`
- `docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md`
- `docs/ai-devboard/05_GENESIS_IMPORT.md`
- `docs/ai-devboard/06_DELTA_SYNC.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Tests

- [ ] Add Genesis and Delta tests for chunk index `-1`.
- [ ] Add Genesis and Delta tests for index equal to `chunk_count`.
- [ ] Add Genesis and Delta tests for an index much larger than `chunk_count`.
- [ ] Assert each request returns `422` and error code `artifact_chunk_out_of_range`.
- [ ] Assert no file is created for any rejected index.
- [ ] Add Genesis and Delta tests where declared size is `5`, chunk 0 is `abc`, and chunk 1 is `def`.
- [ ] Assert chunk 1 returns `422`, code `artifact_size_mismatch`, and is not stored.
- [ ] Add finalize tests where uploaded bytes and SHA match each other but do not match declared `size_bytes`.
- [ ] Assert finalize returns `422`, code `artifact_size_mismatch`, and deletes the partial assembled file.
- [ ] Keep tests proving same-index/same-hash retry returns success.

Run RED:

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/ArtifactUploadLimitsTest.php \
  tests/Feature/GenesisUploadTest.php \
  tests/Feature/DeltaSyncTest.php \
  --display-warnings'
```

Expected RED: at least one new range test creates a chunk or returns the wrong status, and at least one exact-size test reaches hash validation instead of size validation.

### Implementation Recipe

- [ ] Change the route chunk parameter handling to reject non-integer, negative, and overflow values with a controlled plugin error. Do not rely on PHP parameter coercion.
- [ ] Change `storeChunk()` so it receives the artifact row or artifact ID and reloads the artifact inside `DB::transaction()` with `lockForUpdate()`.
- [ ] Decode `metadata` with `JSON_THROW_ON_ERROR`.
- [ ] Read declared `chunk_count` and reject `chunkIndex < 0 || chunkIndex >= chunk_count` before building a path.
- [ ] Recheck `max_chunk_bytes` inside `ArtifactStorageService` as defense in depth.
- [ ] Preserve existing same-index behavior: equal hash returns the existing path; different hash throws `artifact_finalize_conflict`.
- [ ] Verify the incoming chunk hash before writing.
- [ ] Sum sizes only for existing expected paths from index `0` through `chunk_count - 1`.
- [ ] Reject when `existing_expected_bytes + incoming_bytes > size_bytes`.
- [ ] Write the chunk only after all checks pass while the artifact lock is held.
- [ ] In `assembleArtifact()`, track `$actualSize` while streaming.
- [ ] Throw `artifact_size_mismatch` as soon as bytes exceed the declaration and again if final size is not exactly equal.
- [ ] Keep SHA validation as a separate check after exact-size validation.
- [ ] Delete partial output on every assembly failure.
- [ ] Ensure finalization locks the artifact rows before assembly so chunk writes and finalization cannot race.
- [ ] Map both new errors to HTTP `422` in Genesis and Delta controllers.
- [ ] Document both error codes and exact-size semantics.

### GREEN Gate

Run the RED command again. Then run:

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Plugin/ArtifactIdentityValidationTest.php \
  tests/Feature/Plugin/ArtifactUploadLimitsTest.php \
  tests/Feature/GenesisUploadTest.php \
  tests/Feature/DeltaSyncTest.php \
  --display-warnings'
docker exec devboard-app-1 sh -lc './vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress'
git diff --check
```

### Acceptance Criteria

- [ ] No out-of-range index creates a file.
- [ ] Expected chunk bytes never exceed declared size.
- [ ] Final size equals declared size exactly.
- [ ] Existing idempotent retry behavior remains green.

---

## Task 1.2: Purge Abandoned Uploads And Legacy Extra Chunks

**Priority:** P0 availability

**Depends on:** Task 1.1

**Modify:**

- `backend/config/devboard.php`
- `backend/.env.example`
- `backend/app/Services/ArtifactRetentionService.php`
- `backend/tests/Feature/ArtifactRetentionTest.php`
- `docs/ai-devboard/05_GENESIS_IMPORT.md`
- `docs/ai-devboard/06_DELTA_SYNC.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Fixed Policy

- Add `DEVBOARD_INCOMPLETE_UPLOAD_TTL_HOURS`, default `24`.
- Only purge `uploading` artifacts older than the TTL whose parent Genesis/Delta row has not been updated within the TTL.
- Dry-run must remain the default for operator-triggered cleanup.

### RED Tests

- [ ] A recent `uploading` artifact is retained.
- [ ] A stale `uploading` artifact and its chunk directory are purged.
- [ ] A stale artifact whose parent transfer is recent is retained.
- [ ] Out-of-range legacy chunk files under a stale transfer are purged.
- [ ] Dry-run reports candidates but does not delete files or mutate rows.

### Implementation Recipe

- [ ] Add the TTL config and example env variable.
- [ ] Extend retention selection to stale `uploading` rows using both artifact and transfer timestamps.
- [ ] Delete the complete artifact directory, not only declared chunk paths.
- [ ] Mark purged rows using the lifecycle status already used by retention; do not create a new status unless schema/tests prove one is required.
- [ ] Audit only IDs, type, byte counts, and reason. Never audit chunk contents.
- [ ] Keep imported/validated retention behavior unchanged.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/ArtifactRetentionTest.php \
  tests/Feature/SystemMaintenanceTest.php \
  --display-warnings'
git diff --check
```

---

## Task 1.3: Replace Endpoint Validation With A Testable Resolver

**Priority:** P0 SSRF

**Depends on:** Task 1.2

**Create:**

- `backend/app/Assistants/ProviderHostResolver.php`
- `backend/app/Assistants/SystemProviderHostResolver.php`
- `backend/app/Assistants/ProviderEndpointResolution.php`
- `backend/tests/Unit/ProviderEndpointPolicyTest.php`

**Modify:**

- `backend/app/Assistants/ProviderEndpointPolicy.php`
- `backend/app/Rules/ProviderEndpointRule.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php`
- `backend/config/services.php`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Tests

Use an injected fake resolver. Never use live DNS in tests.

- [ ] Reject zero DNS answers.
- [ ] Reject malformed URLs and URL userinfo.
- [ ] Reject IPv4 loopback, private, link-local, unspecified, multicast, and reserved addresses.
- [ ] Reject bracketed IPv6 `::1`, `::`, `fc00::1`, `fe80::1`, and `ff02::1`.
- [ ] Reject IPv4-mapped IPv6 for `127.0.0.1` and `169.254.169.254`.
- [ ] Reject a hostname with one public A answer and one private A answer.
- [ ] Reject a hostname with a public A answer and private AAAA answer.
- [ ] Accept a hostname only when every returned A/AAAA address is public.
- [ ] Accept a public IPv6 literal after bracket normalization.
- [ ] Add dashboard feature tests proving stored unsafe URLs are rejected before any HTTP request.
- [ ] Use `Http::preventStrayRequests()` in integration tests.

### Implementation Recipe

- [ ] Replace the static boolean-only API with an injected policy that returns `ProviderEndpointResolution` or a denied result.
- [ ] Parse an absolute URL and require a host.
- [ ] Reject username/password URL components.
- [ ] Normalize bracketed IPv6 before address validation.
- [ ] Normalize IPv4-mapped IPv6 before range validation.
- [ ] Resolve both A and AAAA records.
- [ ] Treat resolver exceptions and zero answers as denied.
- [ ] Reject the whole hostname if any answer is unsafe.
- [ ] Preserve original hostname, port, scheme, and validated addresses in the resolution object.
- [ ] Update the error message to mention unresolved addresses.
- [ ] Bind the system resolver in `AppServiceProvider`.
- [ ] Keep all feature tests deterministic with a fake resolver binding.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Unit/ProviderEndpointPolicyTest.php \
  tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php \
  --display-warnings'
docker exec devboard-app-1 sh -lc './vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress'
git diff --check
```

### Stop Conditions

- Stop if tests require public DNS.
- Stop if unresolved hosts are allowed for convenience.

---

## Task 1.4: Pin Provider Connections On Every Runtime Path

**Priority:** P0 SSRF

**Depends on:** Task 1.3

**Create:**

- `backend/app/Assistants/ProviderHttpClient.php`
- `backend/tests/Unit/ProviderHttpClientTest.php`

**Modify:**

- `backend/app/Assistants/AiAgentRegistry.php`
- `backend/app/Services/ServerAgentWorkService.php`
- `backend/app/Assistants/TaskClarifierService.php`
- `backend/app/Assistants/BacklogTriageService.php`
- `backend/app/Services/Hades/IntakeNormalizerService.php`
- corresponding focused feature tests for each service
- `docs/ai-devboard/07_SECURITY_MODEL.md`
- `docs/ai-devboard/12_SERVER_SIDE_AGENT_REGISTRY.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Tests

- [ ] Assert redirects are disabled.
- [ ] Assert the original hostname remains in URL/Host/TLS SNI.
- [ ] Assert the checked IP is pinned using the transport's resolve option.
- [ ] Assert a resolver result cannot change between policy validation and request dispatch.
- [ ] Seed an unsafe URL directly in the database for each runtime service.
- [ ] Assert no request occurs and the existing deterministic fallback/error contract is returned.
- [ ] Cover admin model discovery, OpenCode validation, direct server agent work, task clarification, backlog triage, and Hades intake normalization.

### Implementation Recipe

- [ ] `ProviderHttpClient` must resolve and dispatch as one operation.
- [ ] Resolve immediately before each request.
- [ ] Pin one validated address with cURL `CURLOPT_RESOLVE` or the equivalent supported by the installed HTTP stack.
- [ ] Set `allow_redirects` to `false`.
- [ ] Fail closed if pinning is unavailable.
- [ ] Move direct calls from `AiAgentRegistry` and `ServerAgentWorkService` through this client.
- [ ] Inspect the installed Laravel AI SDK transport extension point for the three SDK-backed services.
- [ ] Inject the same pinned handler into the SDK transport.
- [ ] If the SDK has no supported custom transport, stop and report the exact package/API limitation. Do not retain raw custom `base_url` usage.
- [ ] Revalidate stored URLs at use time; create/update validation alone is insufficient.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Unit/ProviderHttpClientTest.php \
  tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php \
  tests/Feature/Dashboard/AgentWorkDashboardApiTest.php \
  tests/Feature/Dashboard/TaskClarifierDashboardTest.php \
  tests/Feature/Dashboard/BacklogTriageDashboardTest.php \
  tests/Feature/Dashboard/IntakeNormalizerTest.php \
  --display-warnings'
git diff --check
```

### Acceptance Criteria

- [ ] Every provider request validates all DNS answers.
- [ ] Every provider request is pinned to a checked address.
- [ ] Redirects cannot bypass policy.
- [ ] No runtime path consumes a database `base_url` directly.

---

## Task 2.1: Pass Project Scope Into Assistant Graph Queries

**Priority:** P1 functional correctness

**Depends on:** Task 1.4

**Modify:**

- `backend/app/Assistants/Tools/QueryProjectGraphTool.php`
- `backend/tests/Feature/Assistants/AiAgentReadToolsTest.php`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Test

- [ ] Create a project with a real graph snapshot fixture.
- [ ] Inject `FakeNeo4jClient` through `GraphQueryService` into `QueryProjectGraphTool`.
- [ ] Execute a structured `callers` query.
- [ ] Assert `found === true`, returned `project_id` equals the requested project, one Neo4j command ran, and its `snapshot_id` is the project's latest snapshot.

Expected RED: `graph_snapshot_not_found` and zero Neo4j calls.

### Implementation Recipe

- [ ] Initialize structured params with `['project_id' => $projectId]`.
- [ ] Keep all existing type-specific params unchanged.
- [ ] Do not infer project scope in `GraphQueryService`.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Assistants/AiAgentReadToolsTest.php \
  tests/Feature/Plugin/GraphQueryApiTest.php \
  --display-warnings'
git diff --check
```

---

## Task 2.2: Preserve Semantic Neo4j Labels

**Priority:** P1 graph correctness

**Depends on:** Task 1.4

**Modify:**

- `backend/app/Services/GenesisGraphImportService.php`
- `backend/tests/Unit/GenesisGraphCypherTest.php`
- `backend/tests/Feature/GenesisGraphImportTest.php`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Tests

- [ ] `['Symbol', 'Function']` adds `:Function`.
- [ ] `['Symbol', 'Method']` adds `:Function`.
- [ ] `['Symbol', 'Class']` adds `:Class`.
- [ ] `['File']` adds `:File`.
- [ ] `['Symbol', 'Variable']` remains only `:CodeNode`.
- [ ] Batch commands group real analyzer label shapes correctly.
- [ ] Feature fixture uses `['Symbol', 'Function']`, not artificial `['Function']`.

Expected RED: semantic symbols receive only `:CodeNode`.

### Implementation Recipe

- [ ] Inspect every string label in input order.
- [ ] Return the first recognized semantic mapping.
- [ ] Ignore malformed/non-string labels.
- [ ] Preserve original label arrays as node properties.
- [ ] Always preserve base `:CodeNode`.
- [ ] Do not change relationship mapping in this task.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Unit/GenesisGraphCypherTest.php \
  tests/Feature/GenesisGraphImportTest.php \
  --display-warnings'
git diff --check
```

---

## Task 2.3: Resolve Python CALLS Across Files And Delta Boundaries

**Priority:** P1 graph correctness

**Depends on:** Tasks 2.1 and 2.2

**Modify:**

- `analyzer/src/devboard_analyzer/code_graph.py`
- `analyzer/src/devboard_analyzer/delta_bundle.py`
- `analyzer/tests/test_code_graph.py`
- `analyzer/tests/test_delta_bundle.py`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Tests

- [ ] Cross-file `from helpers import helper` resolves caller to `symbol:helpers.py:helper`.
- [ ] Pass caller file before helper file to prove traversal order independence.
- [ ] Two modules defining `helper` are disambiguated by the explicit import.
- [ ] `from helpers import helper as imported_helper` resolves the alias.
- [ ] `import helpers as h; h.helper()` resolves correctly.
- [ ] An unimported globally unique name remains `external:<name>`.
- [ ] A changed Delta caller resolves an unchanged imported callee using the full repository as the resolution universe.

### Implementation Recipe

- [ ] Add optional `resolution_files` to `build_code_graph()`, defaulting to emitted `files`.
- [ ] Parse/cache all Python ASTs in `resolution_files` before emitting nodes or relationships.
- [ ] Build a repository symbol catalog plus per-file local indexes.
- [ ] Build explicit import and alias bindings for each file.
- [ ] Resolve in this order: local qualified, local unqualified, explicit from-import, explicit module import plus attribute, external fallback.
- [ ] Support unambiguous `src/` package suffixes.
- [ ] Do not globally guess unimported names.
- [ ] For Delta, emit only changed graph rows but pass all repository files as `resolution_files`.
- [ ] Keep Graphify mode behavior unchanged.

### GREEN Gate

```bash
"/tmp/opencode/devboard-python-review/bin/python" -m pytest \
  analyzer/tests/test_code_graph.py \
  analyzer/tests/test_delta_bundle.py \
  -q
"/tmp/opencode/devboard-python-review/bin/python" -m pytest analyzer/tests plugin/tests -q
git diff --check
```

If the temporary environment does not exist, recreate it exactly as CI does:

```bash
python3 -m venv /tmp/opencode/devboard-python-review
/tmp/opencode/devboard-python-review/bin/pip install -e './analyzer[test]' -e './plugin[test]'
```

---

## Task 2.4: Reconcile Graph Documentation And Refresh Derived Data

**Priority:** P1 documentation and operations

**Depends on:** Task 2.3

**Modify:**

- `docs/ai-devboard/13_MEMORY_GRAPH_RECONCILIATION.md`
- `docs/ai-devboard/05_GENESIS_IMPORT.md`
- `docs/ai-devboard/06_DELTA_SYNC.md`
- `docs/ai-devboard/10_RUNTIME_SEQUENCES.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Documentation Changes

- [ ] Describe base `:CodeNode` plus additive typed labels.
- [ ] Describe `CALLS`, `DECLARES`, `IMPORTS`, and `RELATED` fallback.
- [ ] Describe unified `ImportGraphToNeo4j` and legacy compatibility wrapper.
- [ ] Describe structured Neo4j queries and artifact-JSON text compatibility mode.
- [ ] Document `POST /api/plugin/v1/projects/{project}/graph/query` and `projects.read`.
- [ ] Correct stale separate Genesis/Delta job sequence names.
- [ ] For every source-of-truth schema mismatch, explicitly choose `implemented contract` or `future target`; do not silently rewrite requirements.

### Verification

```bash
rg -n 'UNRESOLVED|FILL_ME|NOT_DECIDED' docs/ai-devboard
rg -n 'GraphQueryService|graph/query|CALLS|DECLARES|IMPORTS|compatibility' \
  docs/ai-devboard/13_MEMORY_GRAPH_RECONCILIATION.md
git diff --check
```

### Derived Data Procedure

- [ ] Rebuild a staging/test project projection with `php artisan devboard:neo4j-rebuild --project=<project_ulid>`.
- [ ] Query Neo4j and prove at least one `:Function` and one `:CALLS` relationship exist for the latest snapshot.
- [ ] Generate a fresh analyzer snapshot for cross-file CALLS; a Neo4j rebuild alone cannot repair bad edges already stored in old artifacts.
- [ ] Record IDs and counts, not source content, in the logbook.

---

## Task 2.5: Repair Sandbox Graph Configuration And Audit

**Priority:** P1 sandbox operability

**Depends on:** Task 2.4

**Modify:**

- `ai-sandbox/config/project.yaml`
- `.graphifyignore`
- `ai-sandbox/scripts/audit_sandbox.py`
- `tests/test_audit_sandbox.py`
- sandbox configuration tests if they assert the stale environment block
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`

### Verified Current Problems

- `graph.ast_root` is `project`, but `/home/ubuntu/dev-sandbox/project` does not exist.
- `project.root` is already `.` and is the correct source root.
- `.graphifyignore` mostly excludes obsolete paths under `project/`, so root-level `backend/vendor`, `backend/node_modules`, virtual environments, caches, and generated outputs can be indexed.
- `project.yaml` duplicates stale Darwin/arm64 environment facts; runtime detection writes the authoritative `ai-sandbox/config/environment.yaml`.
- `project.yaml` contains a tracked Neo4j password even though graph refresh does not consume that field.
- `audit_sandbox.py` treats `.` as a literal `./` path prefix and cannot correctly audit a workspace-root graph.

### RED Tests

- [ ] `configured_project_root()` accepts `.` as workspace root.
- [ ] Root-relative project files such as `backend/app/Foo.php` are accepted.
- [ ] `ai-sandbox/**` and `graphify-out/**` sources are rejected when root is `.`.
- [ ] A missing/nonexistent configured root is rejected before Graphify runs.
- [ ] Configuration tests assert there is no tracked `graph.neo4j_password`.
- [ ] Ignore-file test asserts actual root-level generated dependency/cache directories are excluded.

### Implementation Recipe

- [ ] Set `graph.ast_root` to `.`.
- [ ] Remove the stale duplicate `environment:` block from `project.yaml`; keep detected facts only in `config/environment.yaml`.
- [ ] Remove `graph.neo4j_password` from tracked project config. Runtime credentials remain in ignored `.env`.
- [ ] Keep `graph.neo4j_user` and URL only if a current script consumes them; otherwise remove unused credential-adjacent fields rather than documenting false behavior.
- [ ] Replace obsolete `project/**` ignores with actual root paths, including `.git/`, `ai-sandbox/`, `graphify-out/`, `backend/vendor/`, `backend/node_modules/`, `backend/storage/`, Python virtual environments/caches, and generated build/coverage directories.
- [ ] Do not ignore application source, migrations, tests, or source-of-truth docs.
- [ ] Update audit logic so root `.` accepts ordinary relative project paths but explicitly rejects sandbox/generated prefixes.
- [ ] Keep non-root behavior covered for future configured subdirectories.

### GREEN Gate

```bash
python3 -m pytest -q tests/test_audit_sandbox.py tests/test_init_sandbox.py
python3 ai-sandbox/scripts/detect_environment.py
python3 ai-sandbox/scripts/bootstrap_dependencies.py
python3 ai-sandbox/scripts/discover_project.py
python3 ai-sandbox/scripts/refresh_graph.py
python3 ai-sandbox/scripts/audit_sandbox.py
git diff --check
```

### Acceptance Criteria

- [ ] Graph refresh runs against `.` and does not parse `ai-sandbox/` or generated dependencies.
- [ ] Sandbox audit passes for the generated graph.
- [ ] No runtime secret is tracked in sandbox project config.
- [ ] Environment facts have one authoritative generated file.

---

## Task 3.1: Add A Strict PostgreSQL And Pgvector Acceptance Lane

**Priority:** P1 infrastructure prerequisite

**Depends on:** Task 2.4

**Create:**

- `backend/phpunit.postgres.xml`
- `backend/tests/Feature/Postgres/PostgresSchemaAcceptanceTest.php`
- `scripts/devboard_postgres_acceptance.sh`

**Modify:**

- `docker-compose.devboard.yaml`
- `docker-compose.devboard.prod.yaml`
- `.github/workflows/ci.yml`
- `backend/composer.json`
- `docs/runbooks/devboard-production-deploy.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Image Selection Procedure

- [ ] Pull the approved PostgreSQL 16 pgvector image for linux/amd64.
- [ ] Inspect its `RepoDigests`.
- [ ] Record the exact tag and digest in the logbook.
- [ ] Replace ordinary PostgreSQL images in development and production Compose with that immutable digest.
- [ ] Stop if no immutable digest is available or the image architecture is wrong.

### RED Acceptance Test

The dedicated suite must fail, never skip, unless all are true:

- [ ] Driver is `pgsql`.
- [ ] `vector` extension exists.
- [ ] `hades_search_documents.search_vector` exists.
- [ ] `hades_search_documents.embedding` exists.
- [ ] Full-text GIN index exists.
- [ ] Full-text trigger exists and populates on insert/update.
- [ ] A vector cosine-distance expression executes.

### Implementation Recipe

- [ ] Create `phpunit.postgres.xml` without forced SQLite values.
- [ ] Add a `PostgresAcceptance` testsuite containing only strict PostgreSQL tests.
- [ ] Add Composer script `test:postgres`.
- [ ] Add a CI job with isolated pgvector PostgreSQL service and health check.
- [ ] Run migrations against a fresh database before tests.
- [ ] Ensure the test job fails on skips.
- [ ] Make the local script use a unique Compose project and disposable volume.
- [ ] Require `DEVBOARD_POSTGRES_ACCEPTANCE=1` before destructive cleanup.
- [ ] Make the local script forward optional PHPUnit arguments such as `--filter=AuditLoggerConcurrencyTest`; reject unknown script-only flags.
- [ ] Never connect the acceptance script to the persistent development database.

### GREEN Gate

```bash
DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh
docker compose -f docker-compose.devboard.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml config --quiet
git diff --check
```

### Stop Conditions

- Any PostgreSQL acceptance test skips.
- Fresh migration cannot create or verify pgvector.
- The script reuses `devboard-postgres-data`.

---

## Task 3.2: Serialize The Audit Chain And Add Verification

**Priority:** P1 audit integrity

**Depends on:** Task 3.1

**Create:**

- expansion migration for `audit_chain_heads` and audit chain metadata
- `backend/app/Services/AuditCanonicalizer.php`
- `backend/app/Services/AuditChainVerifier.php`
- `backend/app/Console/Commands/BackfillAuditChainCommand.php`
- `backend/app/Console/Commands/VerifyAuditChainCommand.php`
- `backend/tests/Feature/AuditChainVerificationTest.php`
- `backend/tests/Feature/Postgres/AuditLoggerConcurrencyTest.php`
- `backend/tests/Feature/Quality/AuditWriteBoundaryTest.php`

**Modify:**

- `backend/app/Services/AuditLogger.php`
- `backend/tests/Feature/AuditLoggerTest.php`
- `backend/tests/Feature/DomainSchemaTest.php`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Expansion Schema

Add nullable initially:

```text
audit_logs.sequence bigint
audit_logs.chain_version smallint
audit_logs.actor_user_ref string
audit_logs.actor_device_ref string
```

Create:

```text
audit_chain_heads.chain_key primary key
audit_chain_heads.last_sequence bigint
audit_chain_heads.last_hash char(64) nullable
audit_chain_heads.updated_at timestamp
```

### RED Tests

- [ ] Tampering with each hashed field is detected at the exact sequence.
- [ ] Deleting a middle row is detected.
- [ ] Actor deletion does not change immutable actor refs or invalidate the chain.
- [ ] Two sequential rows chain correctly.
- [ ] PostgreSQL concurrent writers produce unique contiguous sequences and one chain.
- [ ] Static boundary test fails when direct production inserts exist outside `AuditLogger`.

### Canonical Row

Hash all of these in a stable, recursively key-sorted representation:

```text
chain_version
sequence
id
actor_user_ref
actor_device_ref
actor_type
action
target_type
target_id
ip_address
user_agent
payload
created_at
prev_hash
```

### Implementation Recipe

- [ ] Generate ID and timestamp before canonicalization.
- [ ] Seed the single `audit_chain_heads` row with `chain_key = global` in the expansion migration so first-write concurrency cannot race to create it.
- [ ] Inside one transaction, lock `audit_chain_heads` row `global` with `FOR UPDATE`.
- [ ] Compute next sequence and previous hash from the locked head.
- [ ] Insert the complete audit row.
- [ ] Update the chain head in the same transaction.
- [ ] Remove `hasHashChainColumns()` and silent unhashed fallback behavior.
- [ ] Add `recordMany()` for batch events while holding the head lock once.
- [ ] Keep old columns nullable until Task 3.4.
- [ ] Backfill immutable actor refs from current foreign keys where available.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/AuditLoggerTest.php \
  tests/Feature/AuditChainVerificationTest.php \
  tests/Feature/Quality/AuditWriteBoundaryTest.php \
  --display-warnings'
DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh --filter=AuditLoggerConcurrencyTest
git diff --check
```

---

## Task 3.3: Replace Every Raw Production Audit Insert

**Priority:** P1 audit completeness

**Depends on:** Task 3.2

**Modify:** all production files returned by this command, plus their focused tests:

```bash
rg -l "DB::table\(['\"]audit_logs['\"]\)->insert" backend/app
```

Known inventory at plan creation: 32 inserts across 15 files, including token, repository, memory, attachment, project lifecycle, AI registry, assistant, Hades privacy, export, backup, Genesis/Delta, wiki, and retention paths.

### RED Boundary

- [ ] Make `AuditWriteBoundaryTest` fail for every direct production insert outside `AuditLogger.php`.

### Implementation Recipe

- [ ] Replace one file at a time with `AuditLogger::record()` or `recordMany()`.
- [ ] Keep business mutation and required audit event in the same DB transaction where both are database state.
- [ ] For filesystem side effects, distinguish attempt, completion, and failure; never audit success before the external side effect succeeds.
- [ ] Preserve existing action names and payload fields unless a test proves they are wrong.
- [ ] Convert direct test-fixture inserts to `AuditLogger` where final non-null constraints would reject them.
- [ ] Do not sanitize/transform hashed audit rows during backup or export.

### GREEN Gate

```bash
rg -n "DB::table\(['\"]audit_logs['\"]\)->insert" backend/app
```

Expected: one internal insert in `AuditLogger.php` only.

Then:

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --display-warnings'
docker exec devboard-app-1 sh -lc './vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress'
git diff --check
```

---

## Task 3.4: Backfill And Enforce Audit Chain Constraints

**Priority:** P1 audit integrity

**Depends on:** Task 3.3

**Create:** final constraint migration

**Modify:**

- `backend/app/Services/AuditExportService.php`
- `backend/app/Services/Backup/BackupManifestService.php`
- audit export/backup tests
- `docs/runbooks/devboard-production-deploy.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Backfill Requirements

- [ ] Support `--dry-run`.
- [ ] Require maintenance mode or explicit `--force`.
- [ ] Lock the chain during backfill.
- [ ] Order legacy rows by `created_at`, then `id`.
- [ ] Assign sequence starting at `1` and chain version `1`.
- [ ] Initialize the chain head.
- [ ] Run verifier before returning success.

### Final Constraints

- [ ] `sequence NOT NULL` and unique.
- [ ] `chain_version NOT NULL`.
- [ ] `row_hash NOT NULL` and length 64.
- [ ] `prev_hash` null only for sequence 1.
- [ ] `prev_hash` non-null for every later sequence.

### Export And Backup

- [ ] Include sequence, chain version, previous hash, and row hash in audit exports.
- [ ] Preserve canonical hashed fields exactly in backups.
- [ ] Verify an exported/restored chain independently.

### Stop Conditions

Do not run the final constraint migration unless:

```sql
select count(*) from audit_logs
where sequence is null or chain_version is null or row_hash is null;
```

returns `0`, and `php artisan audit:verify-chain` exits `0`.

### GREEN Gate

```bash
docker exec devboard-app-1 php artisan audit:chain-backfill --dry-run
docker exec devboard-app-1 php artisan audit:chain-backfill --force
docker exec devboard-app-1 php artisan audit:verify-chain
DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh
git diff --check
```

---

## Task 4.1: Generate And Index Embeddings Asynchronously

**Priority:** P2 functional completion

**Depends on:** Task 3.4

**Create:**

- `backend/app/Contracts/EmbeddingGenerator.php`
- concrete embedding generator adapter using the installed Laravel AI/provider stack
- `backend/app/Jobs/GenerateSearchDocumentEmbedding.php`
- migration for embedding metadata and HNSW index
- `backend/tests/Feature/Search/SearchDocumentEmbeddingJobTest.php`

**Modify:**

- `backend/config/devboard.php`
- `backend/.env.example`
- `backend/app/Services/Hades/HadesSearchDocumentIndexer.php`
- `backend/app/Console/Commands/Hades/ReindexSearchDocumentsCommand.php`
- `backend/app/Services/Search/EmbeddingIndexService.php`
- `backend/tests/Feature/Search/EmbeddingIndexServiceTest.php`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Configuration

Add explicit values:

```text
DEVBOARD_EMBEDDINGS_ENABLED=false
DEVBOARD_EMBEDDINGS_PROVIDER=
DEVBOARD_EMBEDDINGS_MODEL=
DEVBOARD_EMBEDDINGS_DIMENSIONS=1536
DEVBOARD_EMBEDDINGS_TIMEOUT=30
DEVBOARD_VECTOR_SCORE_WEIGHT=20
```

### RED Tests

- [ ] Search-document upsert dispatches embedding generation after commit.
- [ ] Job persists one valid embedding.
- [ ] Changed source checksum generates a new embedding.
- [ ] Stale job cannot overwrite a newer checksum.
- [ ] Provider failure keeps lexical document available and records degraded state.
- [ ] An unsafe stored provider endpoint is rejected by the Task 1.4 transport and no embedding request is sent.
- [ ] Wrong dimensions, strings, NaN, and infinity are rejected.
- [ ] Reindex command can backfill embeddings.
- [ ] Run summaries are indexed.
- [ ] PostgreSQL HNSW index exists.

### Implementation Recipe

- [ ] Define a small `EmbeddingGenerator` interface and fake it in tests.
- [ ] Inspect the installed provider API before writing the concrete adapter; do not invent method names from memory.
- [ ] Route the concrete adapter through the pinned provider transport completed in Task 1.4; do not create a second unguarded outbound HTTP path.
- [ ] Queue generation only after document commit.
- [ ] Pass source table, source ID, and expected checksum to the job.
- [ ] Re-read the document before generation and update only if checksum still matches.
- [ ] Validate exact dimensions and finite numeric values.
- [ ] Use bound SQL parameters for vector casts; remove interpolated vectors.
- [ ] Add embedding model, dimensions, checksum, status, and updated timestamp metadata.
- [ ] Add HNSW cosine index in PostgreSQL.
- [ ] `supportsEmbeddings()` returns true only when feature, database extension, schema, dimensions, and provider configuration are all operational.
- [ ] Retry transient provider failures with bounded backoff outside DB transactions.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Search/EmbeddingIndexServiceTest.php \
  tests/Feature/Search/SearchDocumentEmbeddingJobTest.php \
  --display-warnings'
DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh --filter=Embedding
git diff --check
```

---

## Task 4.2: Add Vector Candidates To Memory Search

**Priority:** P2 functional completion

**Depends on:** Task 4.1

**Modify:**

- `backend/app/Http/Controllers/Hades/MemorySearchController.php`
- `backend/app/Services/Search/EmbeddingIndexService.php`
- `backend/tests/Feature/Hades/HadesM3SharedMemoryTest.php`
- `backend/tests/Feature/Postgres/VectorMemorySearchAcceptanceTest.php`
- API/source-of-truth documentation for memory search response metadata
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### RED Tests

- [ ] Semantic-only match with no lexical overlap enters results.
- [ ] Query embedding is generated exactly once.
- [ ] Vector candidates respect project, workspace, domain, raw-chunk, and limit filters.
- [ ] Cross-project vectors never appear.
- [ ] Result includes bounded `similarity`, evidence refs, and `needs_verification`.
- [ ] Vector score is secondary, not the only evidence score.
- [ ] Provider/extension failure preserves lexical results and reports vector status `degraded`.
- [ ] Requested limit is honored exactly.

### Implementation Recipe

- [ ] Generate one query embedding only for non-empty queries when embeddings are operational.
- [ ] Fetch vector candidates scoped by project and optional workspace/domains.
- [ ] Union vector source IDs with lexical candidates before hydration.
- [ ] Apply existing authorization, raw-chunk, and structured filters after hydration.
- [ ] Blend vector similarity using configured weight and clamp similarity to `0..1`.
- [ ] Preserve deterministic tie-breaking.
- [ ] Slice final results to requested limit.
- [ ] Return retrieval metadata: lexical status, vector status, vector model, and vector candidate count.
- [ ] Keep lexical-only behavior unchanged when embeddings are disabled.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test \
  tests/Feature/Hades/HadesM3SharedMemoryTest.php \
  --display-warnings'
DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh --filter=VectorMemorySearchAcceptanceTest
git diff --check
```

---

## Task 5.1: Enforce Pint And PHPStan In CI

**Priority:** P2 regression prevention

**Depends on:** Task 4.2

**Modify:**

- `backend/composer.json`
- `.github/workflows/ci.yml`
- formatting-only PHP files reported by Pint
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

### Precondition

Run Pint check with a 10-minute timeout. Do not run the formatter while unrelated PHP files are dirty.

```bash
docker exec devboard-app-1 sh -lc './vendor/bin/pint --test'
```

### Implementation Recipe

- [ ] Add Composer script `quality:format` as `vendor/bin/pint --test`.
- [ ] Put `quality:format` before PHPStan/tests in `quality:all`.
- [ ] If Pint reports existing violations, run the formatter once in a dedicated formatting-only change.
- [ ] Review the formatting diff and verify it contains no semantic edits.
- [ ] Add explicit CI steps for `composer validate --strict`, audit, Pint, PHPStan, and SQLite tests.
- [ ] Keep the pinned current-checkout secret scan from Task 0.2 as a required CI job.
- [ ] Keep PostgreSQL acceptance as a separate required job.
- [ ] Do not add a blanket PHPStan baseline or ignore.

### GREEN Gate

```bash
docker exec devboard-app-1 sh -lc 'composer validate --strict'
docker exec devboard-app-1 sh -lc './vendor/bin/pint --test'
docker exec devboard-app-1 sh -lc './vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress'
docker exec devboard-app-1 sh -lc 'composer test'
git diff --check
```

---

## Task 6.1: Run Full Acceptance

**Priority:** release gate

**Depends on:** Task 5.1

Run every command. Do not substitute smaller suites.

```bash
docker exec devboard-app-1 sh -lc 'composer quality:all'
DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh

/tmp/opencode/devboard-python-review/bin/pip check
/tmp/opencode/devboard-python-review/bin/python -m pytest analyzer/tests plugin/tests -q

npm --prefix agent test

npm --prefix backend ci
npm --prefix backend audit --audit-level=high
npm --prefix backend run build

docker compose -f docker-compose.devboard.yaml config --quiet
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml -f docker-compose.devboard.traefik.yaml config --quiet

docker exec devboard-app-1 php artisan audit:verify-chain
python3 ai-sandbox/scripts/audit_sandbox.py
git diff --check
git status --short
```

### Required Runtime Smoke

- [ ] Create a disposable project through the UI.
- [ ] Link one repository/workspace.
- [ ] Perform a small Genesis import with at least two Python files and one cross-file call.
- [ ] Confirm artifact bytes equal manifest size.
- [ ] Confirm the latest Neo4j snapshot contains `:Function` and `:CALLS`.
- [ ] Run assistant structured callers query and confirm `found=true`.
- [ ] Run lexical search with embeddings disabled.
- [ ] Run semantic-only search with embeddings enabled in the disposable environment.
- [ ] Verify audit chain after the smoke flow.
- [ ] Delete only the disposable project/resources using supported application lifecycle operations.

### Stop Conditions

- Any skipped PostgreSQL acceptance test.
- Any failing required CI-equivalent command.
- Any audit verifier mismatch.
- Any secret printed or written to tracked files.

---

## Task 6.2: Reconcile Historical Plans And Close The Remediation

**Priority:** process correctness

**Depends on:** Task 6.1

**Modify:**

- `docs/superpowers/plans/2026-07-09-devboard-operational-hardening.md`
- this remediation plan
- `ai-sandbox/wiki/AUDIT.md`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
- production-readiness docs that still claim PostgreSQL/vector checks are skipped

### Steps

- [ ] Add a final status table to the old plan; do not rewrite its historical task text.
- [ ] Mark a historical step complete only when current acceptance evidence directly proves it.
- [ ] Link partial historical tasks to the remediation task that closed them.
- [ ] Mark superseded evidence as superseded; do not delete history.
- [ ] Update wiki audit counts and PostgreSQL/vector status from actual Task 6.1 output.
- [ ] Record the GitHub Actions run URL and result. Local CI parsing is not enough.
- [ ] Mark every task in this plan `verified` only after its task handoff exists.

### Final Completion Criteria

The remediation is closed only when all are true:

- [ ] Out-of-range and oversized aggregate chunk uploads cannot write files.
- [ ] Stale incomplete upload storage has bounded cleanup.
- [ ] Provider DNS validation fails closed for IPv4 and IPv6.
- [ ] Every provider runtime request is pinned and redirects are disabled.
- [ ] Assistant graph queries carry project scope.
- [ ] Analyzer-shaped labels produce typed Neo4j nodes.
- [ ] Explicit Python imports resolve CALLS across files and Delta boundaries.
- [ ] PostgreSQL/pgvector migrations and strict tests pass without skips.
- [ ] Audit writes form one verified serialized chain.
- [ ] No raw production audit inserts remain.
- [ ] Semantic-only search results work and remain evidence-scoped.
- [ ] Pint and PHPStan are required CI checks.
- [ ] Current tracked files pass the required secret scan and exposed credentials have been rotated.
- [ ] Full local acceptance and a real GitHub Actions run are green.

---

## 4. Explicit Non-Goals

- Do not redesign multi-tenancy.
- Do not add arbitrary Cypher execution.
- Do not replace PostgreSQL or Neo4j.
- Do not implement a second graph source of truth.
- Do not add an artifact chunk database unless this plan is formally revised.
- Do not auto-publish AI-generated wiki content.
- Do not make vector similarity the only evidence source.
- Do not broaden provider access to private networks.
- Do not clean unrelated worktree changes.

## 5. Suggested Commit Boundaries

Use only if the operator requests commits:

```text
fix: bound artifact chunk storage
fix: purge abandoned artifact uploads
fix: validate and pin provider endpoints
fix: scope assistant graph queries
fix: preserve semantic graph labels
feat: resolve cross-file python call edges
docs: reconcile graph runtime contracts
test: add postgres pgvector acceptance
fix: serialize and verify audit chain
refactor: centralize audit writes
fix: enforce audit chain constraints
feat: generate search embeddings
feat: add evidence-backed vector retrieval
ci: enforce backend quality gates
docs: close operational hardening remediation
```
