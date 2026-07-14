# Task 2 report

Status: DONE_WITH_CONCERNS

Implemented the scoped canonical graph handle, explorer, preview, semantic edge, and rotation work with strict RED/GREEN sequencing. No migration, database mutation, container, deployment, push, or commit was performed.

Rotation review gate:

- Projector RED observed for missing public_handle_key_version metadata and active-version handle binding.
- Dashboard RED observed for returning ready instead of graph_projection_rebuild_required on a stored-key mismatch.
- Explorer RED observed for direct resolution without the current key-version/fingerprint guard.
- GREEN stamps key version/fingerprint on existing CanonicalGraphVersion metadata, checks exact project/scope/active version through an injectable Neo4j client, returns an empty rebuild-required preview on mismatch, and resolves handles with one guarded indexed query.
- A post-rebuild active-version test confirms the old handle returns node_not_found.

Verification:

- DashboardGraphExplorerServiceTest.php: 14 tests, 147 assertions.
- CanonicalGraphProjectionTest.php: 25 tests, 100 assertions.
- DashboardApiContractTest.php: 27 tests, 527 assertions.
- Focused combined gate: 92 passing tests, 925 assertions, 2 required Neo4j smoke tests skipped.
- PHP lint and git diff --check passed.

Concern: live Neo4j smoke tests were not run because their explicit environment variables were not supplied. Dashboard production now falls back to Neo4jClientFactory and fails closed on unavailable/error; the dashboard test harness supplies current-key metadata, while rotation tests override it with mismatch and error clients.
- Projector correction: handles are bound to the physical candidate projection.graph_version. The fixture models logical_graph_version=origin-v1, graph_version=published-v2, and prior active_graph_version=old-v1.
- Explorer handle-path correction: search and impact now guard exact project/scope/active-version and key metadata in Cypher; overview checks compatibility and fails closed without requiring Neo4j current=true. Full explorer plus dashboard contract verification passed 46 tests/722 assertions.
- Lifecycle correction: forced-candidate rotation now verifies key A to B fingerprint change, candidate-bound handles, publishPublicationAttempt plus publishCurrent, PostgreSQL active_graph_version advancement, and Explorer node_not_found for the old-key handle.

Laudis result-shape correction:

- Added one shared `Neo4jResultMaterializer::materializeRows()` helper for iterable results and `toArray()`/`ArrayAccess` rows, including recursive nested Cypher values.
- Dashboard key compatibility and every direct Explorer Neo4j run result now materialize real Laudis `SummarizedResult`/`CypherList`/`CypherMap` values before reading rows.
- Observed a genuine vendor-shaped RED where Explorer detail returned `found=false`; the actual vendor-shaped regression now passes for detail, overview, search, and impact.
- Dashboard/Explorer suites: 47 tests, 730 assertions. Broader Task 2 graph gate: 99 passed, 2 skipped Neo4j smoke tests, 993 assertions. PHP lint and `git diff --check` passed; Pest temp results were restored.

Missing active-version correction:

- Added a RED contract for a `ready` projection with a null active graph version; it previously returned `projection_status: ready`.
- Dashboard now treats missing, empty, or whitespace-only active graph versions as `graph_projection_rebuild_required` and returns an empty non-clickable preview.
- Dashboard/Explorer suites now pass 48 tests and 735 assertions; Pest temp results were restored.

Projector missing-key preflight correction:

- Strengthened the missing-`APP_KEY` RED to assert the Neo4j fake receives zero commands; before the fix it recorded index creation and version metadata writes.
- `keyFingerprint()` now fails closed on an empty key. The projector preflights and stores the key version/fingerprint before any Neo4j write, reusing those values for CanonicalGraphVersion metadata.
- Projector, public-handle, Explorer, and dashboard suites pass 79 tests and 864 assertions. PHP lint passed and Pest temp results were restored.

Pagination quality correction:

- Added and observed RED coverage for search bounded limits/precise cursor score round trips, impact exact-limit versus extra-row truncation and deterministic edge ordering, and scopes latest-ready-per-scope SQL pagination with cursor boundaries.
- Search now uses one 1..100 bounded limit, fetches/slices/reports `boundedLimit + 1`, and encodes scores with `%.17g`. Impact fetches plus one with deterministic tiebreakers, truncates before grouping, and sorts/deduplicates edge types before constructing `why`. Scopes use a SQL `ROW_NUMBER()` latest-ready-per-scope subquery, SQL cursor boundaries, stable ordering, and bounded `LIMIT`.
- Full Explorer service suite: 22 tests/203 assertions. Broader Task 2 gate: 104 passed, 2 skipped Neo4j smoke tests, 1,019 assertions. Pest temp results were restored and `git diff --check` passed.

Semantic quality correction:

- Added RED/GREEN coverage for full Lucene QueryParser operator escaping, empty/invalid normalized search rejection before index access, exact-start bounded neighborhood traversal with direction validation, strict family validation, and technical legacy public values.
- Search now escapes `&&`/`||` and rejects empty, invalid UTF-8, and overlong normalized queries. Neighborhood uses bounded `traverse` with `start_external_id` and no candidate `CONTAINS` scan. Unknown family lists fail closed; empty lists retain the all-family behavior. Projector and Explorer reject technical legacy prefixes from searchable/public labels.
- Broader Task 2 gate: 109 passed, 2 skipped Neo4j smoke tests, 1,048 assertions. PHP lint and `git diff --check` passed; Pest temp results were restored.

### A31 canonical encoding hardening

- Added TDD coverage that flips only the unused trailing base64url bits in a `gh1` public handle and in each `gc1` payload/signature segment. Test-side strict decoding proves each alias has identical decoded bytes, while the production validators reject every alias.
- Replaced the cursor's hardcoded expected string assertion with independently constructed canonical JSON, base64url, and HMAC-SHA256 bytes. The test also proves encode-twice stability and rejects a cursor signed under the previous `APP_KEY` after rotation.
- Cursor encoding rejects invalid UTF-8 query input. Explorer search rejects independently signed cursors with project, scope, graph-version, query-type, or query mismatches before issuing any Neo4j command.
- The existing production validators already contained the required decode/reencode checks and UTF-8 guard from the preceding review work, so these A31 tests passed without an additional production API or implementation change.
- A31 focused gate: 37 tests, 283 assertions, all passing. Broader graph gate: 111 tests, 109 passing, 2 required Neo4j smoke tests skipped, 1,054 assertions. PHP lint and `git diff --check` passed; the Pest temporary result artifact was restored after each run. No commit was created.

### A32 final-review fixes

- Path sanitization: added RED/GREEN coverage for `/home/ubuntu/dev-sandbox/Secret.php`, `/srv/private/app/Invoice.php`, Windows drive paths, UNC paths, and file URIs in projector fields. One private technical-identity guard now serves both `safeSearchValue()` and `safeRoutePath()`, `/api/invoices/{id}` remains valid, `public_search_label` no longer falls back to `kind`, and `kind` remains separate.
- Real edge endpoints: projector relationship properties now persist `source_id` and `target_id`, allowing path `properties(r)` to materialize endpoint handles. Public path/neighborhood/acceptance responses continue to expose handles and edge vocabulary only, never raw IDs.
- Impact existence: `impact()` now reuses the guarded indexed `resolveNode()` path. Well-formed nonexistent, tampered, cross-context, and old-key handles return `node_not_found`; an existing node with no impacts returns `found: true` and an empty item list.
- Limit contracts: path slices public items and filters edges to retained endpoints; neighborhood gives the root an internal budget slot without consuming the public neighbor limit; impact groups affected node/family rows before `LIMIT $fetch_limit`, then slices public groups and computes truncation from grouped results. Mixed-family and duplicate-path coverage prevents distinct groups from disappearing.
- Family ranking/indexes: traversal filters `edge_type` before deterministic rank ordering and no longer applies a global `rank < limit` predicate. Direction and any adjacency indexes now include `edge_type`; exact family vocabulary is asserted. Added a read-only environment-gated live acceptance test for search, path, neighborhood, and impact with raw identity/path leak checks; it skips locally without the required `NEO4J_GRAPH_ACCEPTANCE_*` variables.
- A32 focused gate: 92 tests, 89 passing, 3 skipped (two existing Neo4j smoke tests plus the new live acceptance test), 557 assertions. Broad Task 2 gate: 118 tests, 116 passing, 2 existing Neo4j smoke tests skipped, 1,109 assertions. PHP lint and `git diff --check` passed; Pest temporary results were restored. No commit was created.

### A32 sanitizer follow-up

- Added RED coverage for `/root`, `/etc`, `/mnt`, `/Volumes`, `/app`, `backend/app/Foo.php`, and `src/Foo.ts`. The existing private technical-identity guard now rejects those absolute roots and common relative source prefixes through both public search fields and route-path sanitization.
- Preserved legitimate `/api/invoices/{id}` and `/.well-known/openid-configuration` route paths.
- Follow-up focused gate: 93 tests, 90 passing, 3 skipped, 567 assertions. Broad Task 2 gate: 119 tests, 117 passing, 2 existing Neo4j smoke tests skipped, 1,119 assertions. PHP lint and `git diff --check` passed; Pest temporary results were restored. No commit was created.

### A33 second independent-review blockers

- Filesystem identity privacy: added RED coverage for arbitrary POSIX roots (`/data`, `/Applications`, `/root`, `/etc`, `/mnt`, `/Volumes`, `/app`), dot/parent-relative source paths, source-tree-relative paths, drive-relative and drive-absolute Windows paths, UNC/device paths, file URIs, and embedded path payloads. The projector now uses one private classifier for `safeSearchValue()` and `safeRoutePath()`. Route paths require explicit `route_registry`, `http_route`, or `legacy_route_record` provenance; unproven route nodes are rejected. `/api/invoices/{id}` and `/.well-known/openid-configuration` remain valid, while source-looking route values are rejected.
- Neo4j index upgrade: changed the edge-type composite indexes to independent idempotent names `canonical_adjacency_direction_edge_type_rank_v2` and `canonical_adjacency_any_edge_type_rank_v2`. The historical `canonical_adjacency_direction_rank` and `canonical_adjacency_any_rank` names are not dropped or reused. Existing installations retain those old indexes while the new definitions are created and awaited alongside them; the new definitions place `edge_type` before the rank property and can be removed later only through a separately reviewed operational cleanup.
- Live acceptance: `CanonicalGraphExplorerNeo4jAcceptanceTest` now requires `NEO4J_GRAPH_ACCEPTANCE_ENABLED=1` plus explicit project/scope/version, from/to handles, query, expected search handle, expected neighborhood handle, expected path handle, and expected impact handle variables. Missing enabled-mode configuration fails with a precise variable list. The test wraps the real client read-only, requires non-empty results, `gh1_` handles, endpoint membership, allowed semantic families/types, bounded/truncated responses, and absence of raw IDs/filesystem paths. It skips only when disabled.
- A33 projector RED: 31 tests, 29 passing, 2 failures, 144 assertions. A33 focused projector GREEN: 31 passing tests, 163 assertions.
- Final focused Task 2 graph gate: 148 tests, 145 passing, 3 skipped, 1,284 assertions. The environment-gated acceptance test remains skipped locally because no live fixture variables were supplied. The enabled-without-fixtures check failed as designed with the complete missing-variable list.
- Full backend gate before the DeltaSync correction: 953 tests, 933 passing, 8 skipped, 10 failures/errors. This exposed a Task 2 integration regression in `ImportGraphToNeo4j::fakeClient()`; it did not answer the projector’s new adjacency verification query.

### A33 DeltaSync fake-client integration correction

- Added a RED test that finalizes a minimal delta and invokes the actual `ImportGraphToNeo4j('delta', ...)` job in fake mode. RED was 1 test, 4 assertions, failing with `Canonical graph adjacency verification count mismatch`.
- Updated the production job fake client to return `expected_adjacencies` for `RETURN count(a) AS adjacencies`. Audited every production/test fake containing the canonical node/relationship verification contract; all now handle both verification queries (`ImportGraphToNeo4j`, `FakeNeo4jClient`, `Neo4jRebuildService`, and graph-import test fakes).
- The RED test is GREEN: 1 test, 6 assertions. Full isolated `DeltaSyncTest.php`: 20 tests, 20 passed, 143 assertions.
- Full relevant Task 2 integration gate, including graph unit/services, canonical projection/traversal, plugin/dashboard/assistant contracts, acceptance, DeltaSync, GenesisGraphImport, Hades canonical projection, and rebuild command tests: 281 tests, 278 passed, 3 skipped, 2,332 assertions.
- Corrected full backend gate: 954 tests, 938 passed, 8 skipped, 7 failures plus 1 error. No DeltaSync fake-client failures remain. Remaining failures are unrelated environment/runtime conditions (missing Neo4j credentials in Hades jobs, two legacy Hades endpoint 404s, and the test storage-directory error). No database, container, deployment, or live graph mutation was performed.

### A34 final-review fixes

- FQCN privacy: added RED coverage for valid `App\\Services\\InvoiceService`, `Domain\\Billing\\ChargeInvoice`, and a leading-root namespace, plus drive-absolute/drive-relative, dot/parent-relative, UNC/device, embedded, and source-tree backslash paths. The projector now validates PHP namespace grammar before allowing backslashes; arbitrary backslash strings remain technical identities.
- Preview privacy: removed unsafe route-label restoration and replaced the finite-root preview check with structural filesystem-identity classification. Canonical preview routes are labeled only from the repository's private trusted-producer route provenance map; legacy snapshots use the backend route policy. Unsafe POSIX, relative, Windows, UNC/device, file-URI, and embedded path values are omitted, while `/api/invoices/{id}` and `/.well-known/openid-configuration` remain valid.
- Route provenance: repository normalization now runs for contracted and legacy graphs, stamps trusted route records including pre-existing legacy nodes, normalizes URI values without a leading slash, removes producer-supplied provenance claims from public properties, and keeps the trusted-producer marker outside the persisted public property bag.
- Search pagination: added a bounded invalid-row pagination regression. Search continues in capped batches until it has a public page plus one public extra row or reaches its hard scan ceiling, preserving score/handle cursor order so technical rows do not consume the page.
- TDD evidence: each A34 finding had a real RED before production edits: FQCN 1 test/1 assertion; preview 1/9; repository 1/1; pagination 1/1. Focused A34 GREEN passed 4 tests/46 assertions.
- Verification: final focused Task 2 gate passed 284 tests, 281 passed, 3 skipped, 2,370 assertions. Isolated `DeltaSyncTest.php` passed 20 tests/143 assertions. The broad backend gate passed 958 tests, 942 passed, 8 skipped, 7 failures plus 1 error; the failures remain the known missing-Neo4j-credentials/legacy-Hades-route/storage-directory environment conditions and are not claimed as fixed by A34. Changed/untracked PHP lint, `git diff --check`, and Pest artifact restoration passed. No commit, push, deploy, database, container, or live-graph mutation was performed.

### A34 route provenance discriminator follow-up

- Added a repository RED proving the prior boolean inversion: the untrusted legacy `/data/private/secret` route was stamped while the trusted `/projects/{id}/wiki` route was rejected. The RED was 1 test and 1 assertion.
- Renamed the local policy state to `trustedProducerRoute`; `normalizedRouteUri()` now returns `null` without trusted producer-derived provenance and accepts any syntactically safe trusted route after normalization. Producer-supplied `route_provenance` remains non-authoritative and is removed.
- Focused GREEN: the new regression plus the existing route-provenance test passed 2 tests / 12 assertions. The complete repository feature file passed 16 tests / 68 assertions; projection/dashboard route consumers passed 63 tests / 746 assertions.
- Final focused Task 2 graph gate passed 284 tests, 281 passed, 3 skipped, 2,370 assertions. The skips are the existing environment-gated Neo4j checks, including disabled live acceptance. PHP lint, `git diff --check`, and Pest temporary-result restoration remain required before handoff. No commit, push, deploy, database, container, or live-graph mutation was performed.

### A34 trusted multi-segment route consumer follow-up

- Added RED projection and dashboard-preview tests for the exact trusted `/projects/{id}/wiki` route alongside an untrusted route with the same path. Before production edits, both failed: projector emitted a null public path and preview omitted the trusted route (2 tests / 3 assertions).
- Removed only the finite `api`/`.well-known`/single-segment root restrictions. Projector structural filesystem checks and the `private_route_provenance` gate remain in place; dashboard preview retains route syntax, traversal, extension, backslash, and file-URI checks plus the same trusted-producer gate.
- Corrected the older preview fixture to model ambiguous extensionless paths as legacy/unproven unless present in backend route records; producer `route_provenance` claims alone remain insufficient.
- GREEN: the new tests passed 2 tests / 5 assertions; projection and dashboard suites passed 65 tests / 751 assertions. Focused Task 2 graph gate passed 286 tests, 283 passed, 3 skipped, 2,375 assertions. No commit, push, deploy, database, container, or live-graph mutation was performed.

### A35 final-review blockers

- Public kind privacy: added RED coverage across Explorer search, detail, neighborhood, path, and impact for filesystem-shaped and `hades-public-*` producer kinds, plus recognized method/service controls. A single closed `publicKind()` mapper now emits only route/file/module/class/function/method/model/service or `unknown`; raw producer text is never returned.
- Pagination ceiling: search now requests one raw lookahead row on the final bounded batch, tracks the last processed well-formed public handle and score, and signs that raw boundary when no extra public row exists. Exact exhaustion at the ceiling reports no continuation; invalid rows beyond the ceiling remain reachable without exposing external IDs.
- Leading-root FQCN: dashboard preview now shares the optional-leading-backslash PHP FQCN grammar with projector output. Windows/source-path forms remain rejected.
- Route trust terminology: renamed local policy state/comments to trusted producer-derived route semantics. An accepted `graph_contract` is a producer trust boundary, not independent verification of every route; the compatibility key `private_route_provenance` and existing structural/provenance gates remain unchanged.
- TDD evidence: A35 RED was 4 failing tests / 12 assertions across kind privacy, raw-ceiling continuation, partial raw boundary, and leading-root FQCN. A35 focused GREEN passed 6 tests / 24 assertions.
- Verification: full Explorer unit suite passed 36 tests / 295 assertions; projection/repository/dashboard suites passed 82 tests / 822 assertions; complete focused Task 2 gate passed 292 tests, 289 passed, 3 skipped, 2,399 assertions. PHP lint, `git diff --check`, and restoration of the Pest artifact from parent `984a1ad4` were completed. No commit, push, deploy, database, container, destructive, or live-graph mutation was performed.

### A35 terminology-only follow-up

- Renamed downstream route locals/parameters and test titles from server-owned/server-verified wording to `trustedProducerRouteProvenance`, `trustedProducerRoute`, and trusted-route terminology. The compatibility keys `private_route_provenance` and `__hades_server_route_provenance` were unchanged.
- Clarified in repository comments/docs that an accepted `graph_contract` is trusted producer-derived semantics, not independent route verification.
- Fixed the mis-indented Explorer fake-client conditional. No behavior changes were made.
- Focused Explorer/projection/dashboard gate: 102 tests, 102 passed, 1,049 assertions. All changed PHP passed `php -l`; `git diff --check` passed; the Pest artifact was restored from `984a1ad4` and its parent diff is empty. No commit or push was performed.

### Second independent review wave

- TDD RED: the new exact-kind mapper test failed with the missing `DashboardGraphPublicKind` class; the winner regression exposed the newer queued projection masking the ready winner; search still bound unused key-version/fingerprint parameters; and the old simple key fingerprint failed the HMAC contract. Combined RED observation: 104 tests, 64 passed, 21 failed, 19 errors, 417 assertions.
- GREEN implementation: `projectionForRead()` now selects the newest ready row with a non-empty `active_graph_version` before inspecting queued/projecting/failed candidates, while preserving not-ready versus rebuild-required reasons when no ready winner exists. Removed the unused `readyProjection()` helper and search key parameters. Added the single `DashboardGraphPublicKind` mapper with the exact ten-value vocabulary and routed Explorer, controller, and canonical Dashboard preview output through it; legacy aliases map to `unknown`. `keyFingerprint()` now uses `hash_hmac('sha256', 'hades.graph.handle.v1', APP_KEY)`.
- Focused GREEN evidence: public-kind/handle/Explorer unit files passed 50/50 tests with 397 assertions; Dashboard Explorer API passed 54/54 with 362 assertions; the exact focused Task 2/3 command including projection, traversal, plugin, dashboard contract, and preview regressions passed 208 tests, with 206 passed, 2 environment-gated skips, and 1,710 assertions.
- Broad Task 2/3 graph gate passed 465 tests, with 462 passed, 3 skipped, and 3,181 assertions. The skips are the existing environment-gated Neo4j read-only/live acceptance checks without fixture variables. No DeltaSync regression remained in this gate.
- Verification: all changed and untracked PHP passed `php -l`; `git diff --check` passed; `backend/vendor/pestphp/pest/.temp/test-results` was restored from `984a1ad4`, and `git diff 984a1ad4 -- backend/vendor/pestphp/pest/.temp/test-results` produced no output. The plan now states that only scopes/search are cursorable; overview/detail/neighborhood/path/impact remain bounded and non-cursorable. No commit, push, deploy, database, container, or live-graph mutation was performed.

### Third independent review wave

- RED evidence: the mixed queued/stale Explorer regression failed with the previous fallback reason (`graph_projection_not_ready` instead of `graph_projection_rebuild_required`, 1 test/4 assertions). The preview regressions reproduced stale preview exposure; the first winner fixture also exposed a test-only artifact-id omission, which was corrected before GREEN.
- GREEN implementation: Dashboard canonical preview now selects the newest ready projection with a non-empty active version before loading exactly that projection's artifact through `CanonicalGraphRepository::findByIdentity()`. With no ready winner, queued/projecting returns unavailable and stale/failed/ready-without-active returns rebuild-required. Explorer fallback ordering now gives stale/failed explicit status priority over queued/projecting, independent of insertion order.
- GREEN evidence: preview winner/stale regressions passed 2/2 tests with 9 assertions; mixed queued/stale Explorer regression passed 1/1 with 4 assertions. The exact focused Task 2/3 command passed 211 tests, with 209 passed, 2 environment-gated skips, and 1,723 assertions. The broad Task 2/3 gate passed 468 tests, with 465 passed, 3 skips, and 3,194 assertions.
- Documentation: the present-tense Task 3 report now lists exactly `method`, `class`, `method_reference`, `external_class`, `table`, `route`, `trait`, `external_symbol`, `interface`, `file`, and `unknown`.
- Verification: all changed/untracked PHP passed `php -l`; `git diff --check` passed; `backend/vendor/pestphp/pest/.temp/test-results` was restored from `HEAD`, and `git diff HEAD -- backend/vendor/pestphp/pest/.temp/test-results` produced no output. No commit, push, deploy, database, container, or live-graph mutation was performed.

### Final Task 3 artifact-reference privacy correction

- RED: canonical ready winner, stale rebuild-required, and ready-without-active rebuild-required assertions failed because `source.ref` still contained the real artifact id (3 tests, 17 assertions observed before the final assertion adjustments).
- GREEN: canonical ready and rebuild-required DTOs now call `sourceMeta(type: 'canonical_graph')` without `ref`; source type/status/origin/generated_at remain unchanged. Non-canonical source metadata is untouched. The ready-winner test now proves `graph_version`, `active_graph_version`, and `generated_at` come from the ready artifact and asserts that `source.ref` is absent.
- Verification: focused Task 2/3 gate passed 211 tests, 209 passed, 2 environment-gated skips, 1,727 assertions. Broad Task 2/3 gate passed 468 tests, 465 passed, 3 skips, 3,198 assertions. PHP lint and `git diff --check` passed; the Pest artifact was restored from `HEAD`, with an empty `git diff HEAD -- backend/vendor/pestphp/pest/.temp/test-results`. No commit, push, deploy, database, container, or live-graph mutation was performed.
