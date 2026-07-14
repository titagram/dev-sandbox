## Task 2: Correct canonical semantic edges and materialize the non-reversible public node handle

**Files:**

- Create `backend/app/Services/Graph/DashboardGraphPublicHandle.php`.
- Create `backend/app/Services/Graph/DashboardGraphExplorerService.php`.
- Create `backend/app/Services/Graph/DashboardGraphExplorerCursor.php`.
- Modify `backend/app/Services/Graph/CanonicalGraphQueryService.php`.
- Modify `backend/app/Services/Graph/Neo4jCanonicalGraphProjector.php`.
- Modify `backend/app/Dashboard/DashboardApiReader.php` canonical preview mapping.
- Create `backend/tests/Unit/Services/Graph/DashboardGraphPublicHandleTest.php`.
- Create `backend/tests/Unit/Services/Graph/DashboardGraphExplorerCursorTest.php`.
- Modify `backend/tests/Feature/Dashboard/DashboardApiContractTest.php`.
- Modify `backend/tests/Feature/Graph/CanonicalGraphTraverseNeo4jReadOnlyTest.php` with deterministic fake-client coverage, while retaining both existing environment-gated read-only tests.
- Modify `backend/tests/Feature/Assistants/AiAgentReadToolsTest.php` only for the stale canonical-ready baseline fixture and graph version/scope assertions.

**Consumed/produced interfaces:**

   final class DashboardGraphPublicHandle
   {
       public function forNode(string $projectId, string $scopeType, string $scopeId, string $graphVersion, string $externalId): string;
        public function isWellFormed(string $handle): bool;
       public function keyVersion(): string;
       public function keyFingerprint(): string;
   }

    final class DashboardGraphExplorerCursor
    {
        public function encode(string $projectId, ?string $sourceScopeType, ?string $sourceScopeId, string $activeGraphVersion, string $queryType, string $query, string $sortKey): string;
        public function decode(string $cursor): array;
    }

    final class DashboardGraphExplorerService
    {
        public function scopes(string $projectId, int $limit = 50, ?string $cursor = null): array;
        public function overview(string $projectId, string $scopeType, string $scopeId): array;
        public function search(string $projectId, string $scopeType, string $scopeId, string $query, int $limit = 50, ?string $cursor = null): array;
        public function detail(string $projectId, string $scopeType, string $scopeId, string $handle, int $limit = 50): array;
        public function neighborhood(string $projectId, string $scopeType, string $scopeId, string $handle, string $direction, int $maxDepth, int $limit, array $families = []): array;
        public function path(string $projectId, string $scopeType, string $scopeId, string $fromHandle, string $toHandle, int $maxDepth, int $limit): array;
        public function impact(string $projectId, string $scopeType, string $scopeId, string $handle, int $limit = 50): array;
    }

The handle is `gh1_<base64url(hmac_sha256(canonical_json, APP_KEY))>`, where `canonical_json` is `json_encode` with exactly the ordered keys `v`, `project_id`, `scope_type`, `scope_id`, `graph_version`, and `external_id`; `graph_version` is the published `active_graph_version`, not merely the origin `graph_version`. The output contains only the `gh1_` prefix and hash, is non-reversible, and exposes no payload. `Neo4jCanonicalGraphProjector` materializes it as `public_handle` on every `CanonicalGraphNode` and indexes the stored properties `project_id`, `source_scope_type`, `source_scope_id`, `graph_version`, and `public_handle`. Runtime resolution performs one direct indexed lookup against the current published `active_graph_version` and matching `source_scope_type`/`source_scope_id`; it does not decode, enumerate candidate nodes, or scan.

`DashboardGraphExplorerCursor` encodes a canonical JSON payload containing `project_id`, nullable `source_scope_type`/`source_scope_id`, published `active_graph_version`, `query_type`, normalized `query`, and `sort_key`, then signs it with HMAC using the current APP_KEY. Search order is `score DESC, handle ASC`; scope order is `source_scope_type ASC, source_scope_id ASC`. Every list query fetches `limit + 1`, and the next cursor is issued only from the deterministic last sort key. Decode verifies the signature and the service compares every payload field with the current project, selected source scope, active graph version, query type, and query before use.

The canonical query service must use one family predicate for adjacency/path/traverse with the exact live types above, always passing the published `active_graph_version` to Neo4j even when it differs from the origin `graph_version`. `Neo4jCanonicalGraphProjector` must materialize `public_handle`, create a lookup index over the stored `project_id`, `source_scope_type`, `source_scope_id`, `graph_version`, and `public_handle` properties, and create the `canonical_node_search` FULLTEXT index over `graph_version`, `public_search_name`, `public_search_label`, and `public_search_path` only. Search must call `db.index.fulltext.queryNodes` with `graph_version:"<escaped>" AND (public_search_name:<escaped> OR public_search_label:<escaped> OR public_search_path:<escaped>)`, then apply the defensive `WHERE node.graph_version = $activeGraphVersion`; raw `external_id` and raw local path must not be searchable.

- [ ] Write handle/query tests for deterministic canonical JSON output, `isWellFormed` acceptance/rejection, tampered-hash rejection through the direct current-scope lookup, malformed `gh1_` rejection, cross-project/scope/version non-resolution, key-version/fingerprint mismatch, and absence of any decode or payload API.
- [ ] Run the RED command: `cd backend && php artisan test --filter=DashboardGraphPublicHandleTest`; expected failure is that the new handle class/test does not exist.
- [ ] Run the cursor RED command: `cd backend && php artisan test --filter=DashboardGraphExplorerCursorTest`; expected failure is that the signed cursor class/test does not exist; the test cases cover signature tampering and cross-project, source-scope, active-version, query-type, and query mismatches.
- [ ] Implement the handle value object with a fixed-order array of `v`, `project_id`, `scope_type`, `scope_id`, `graph_version`, and `external_id`, `json_encode(..., JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)`, `hash_hmac('sha256', $canonicalJson, (string) config('app.key'), true)`, base64url encoding, and only `gh1_<hash>` output; implement `isWellFormed`, `keyVersion`, and `keyFingerprint`, reject malformed input with the single bounded `invalid_handle` error, and never expose canonical ids or a payload.
- [ ] Implement `DashboardGraphExplorerCursor` as canonical JSON plus an HMAC signature containing project, nullable source scope, active graph version, query type/query, and sort key; reject tampering and any project/scope/version/query mismatch as `invalid_cursor` without revealing cursor payload.
- [ ] Apply deterministic `score DESC, handle ASC` search ordering and `source_scope_type ASC, source_scope_id ASC` scope ordering, fetch `limit + 1` in every cursorable list, and derive `next_cursor` from the final retained sort key.
- [ ] Add rotation tests in `DashboardApiContractTest` and `CanonicalGraphProjectionTest`: rotate APP_KEY, require a new fingerprint, publish a newly projected active graph atomically, assert mismatch returns `graph_projection_rebuild_required` with non-clickable/no preview handles, and assert an old preview handle resolves as `404 node_not_found` rather than being reused.
- [ ] Add a fake Neo4j fixture using only the exact live edge types `CALLS`, `CALLS_METHOD`, `STATIC_CALL`, `USES_DEPENDENCY`, `INSTANTIATES`, `EXTENDS`, `USES_FORM_REQUEST`, `THROWS_EXCEPTION`, `API_RESOURCE_REF`, `ROUTE_HANDLER`, `TEST_COVERS_SYMBOL`, `TEST_IMPORTS`, `TEST_COVERS_ROUTE`, `QUERY_TABLE`, and `ELOQUENT_QUERY`; assert callers/callees and impact classify them without materializing `ROUTES_TO`, `TESTS`, or `READS_TABLE` duplicates.
- [ ] Assert projector writes and every resolver/query fixture use the real Neo4j property names `source_scope_type` and `source_scope_id`, never `scope_type` or `scope_id` on `CanonicalGraphNode`.
- [ ] Add the projector/FULLTEXT RED assertion before implementation: `CanonicalGraphProjectionTest` must expect `public_search_name`, `public_search_label`, `public_search_path`, graph-version Lucene filtering, and no raw `external_id`/local `path` indexing; run `cd backend && php artisan test --filter='CanonicalGraphProjectionTest'`; expected failure is missing sanitized properties/index statements.
- [ ] Run the RED command for the semantic behavior before implementation is complete: `cd backend && php artisan test --filter='CanonicalGraphTraverseNeo4jReadOnlyTest|CanonicalGraphProjectionTest'`; expected failure is an empty caller/callee result or a missing index assertion for the live edge vocabulary.
- [ ] Modify `CanonicalGraphQueryService` so `callers`, `callees`, `path`, and `traverse` use the exact family predicates, preserve `max_depth`/`limit` bounds, resolve a ready projection’s non-null `active_graph_version`, pass that value to every Neo4j query even when origin `graph_version` differs, and return normalized `edge_type`, `family`, source/target handles, and source/target kinds internally for the explorer adapter; test the active-versus-origin case.
- [ ] Add `DashboardGraphExplorerService` methods that call `CanonicalGraphQueryService` for canonical reads, call `GraphQueryService` only through its existing bounded adapter where the caller contract requires it, resolve a requested handle with `MATCH (node:CanonicalGraphNode {project_id: $projectId, source_scope_type: $scopeType, source_scope_id: $scopeId, graph_version: $graphVersion, public_handle: $handle}) LIMIT 1`, and map impact records to `{handle, kind, label, distance, family, edge_types, why}` without candidate scans or internal ids.
- [ ] Implement impact as bounded two-hop reverse traversal over all semantic families, grouping explanations by `family`, exact live `edge_types`, and node kind (`method`, `class`, `method_reference`, `external_class`, `table`, `route`, `trait`, `external_symbol`, `interface`, `file`) so each route, test, table, or symbol reports why it is affected rather than returning a flat inbound list.
- [ ] Add `DashboardApiContractTest` before implementation: fixture the current ready projection and dashboard preview, run `cd backend && php artisan test tests/Feature/Dashboard/DashboardApiContractTest.php`, and observe failure because preview nodes do not yet emit coherent `gh1_` handles.
- [ ] Add the current-projection contract fixture with exactly 200 nodes, including 70 `unknown` kinds and 145 legacy-looking `hades-public-*` values; assert the public response has `unknown_kind_count`, `missing_label_count`, and `excluded_node_count`, `excluded_node_count >= max(unknown_kind_count, missing_label_count)`, rejects `unknown` plus `hades-public-*` as a human label, keeps unresolved nodes only in Machine/detail data, excludes them from the default canvas, and returns only edges whose endpoints remain in the canvas node set.
- [ ] Update `DashboardApiReader` current-projection preview mapping so every node emits a handle from the same logical project/scope/active-graph-version/external-id inputs while reading stored `source_scope_type`/`source_scope_id`; remove any fallback label shaped like `<kind> <hades-public-*>`, use only a sanitized source label or `null`, and never use `id` or `handle` as the human label.
- [ ] Add the stale baseline assertion in `AiAgentReadToolsTest`: fixture a canonical projection with `status=ready`, exact `graph_version`, and project/scope fields, then assert the read-tool context uses those fields rather than a legacy `snapshot_id`; run `cd backend && php artisan test tests/Feature/Assistants/AiAgentReadToolsTest.php` and observe the old fixture/assertion failure before implementation.
- [ ] Update the baseline fixture/expectation to use canonical `graph_version` and scope while preserving the existing read-tool behavior; legacy snapshot identifiers are not accepted as readiness fields.
- [ ] Run the GREEN command: `cd backend && php artisan test --filter='DashboardGraphPublicHandleTest|DashboardGraphExplorerCursorTest|DashboardApiContractTest|AiAgentReadToolsTest|CanonicalGraphTraverseNeo4jReadOnlyTest|CanonicalGraphProjectionTest'`; expected result is all deterministic tests passing, with environment-gated Neo4j tests skipped unless their required variables are present.
- [ ] Check the exact diff with `git diff --check -- backend/app/Services/Graph/DashboardGraphPublicHandle.php backend/app/Services/Graph/DashboardGraphExplorerCursor.php backend/app/Services/Graph/DashboardGraphExplorerService.php backend/app/Services/Graph/CanonicalGraphQueryService.php backend/app/Services/Graph/Neo4jCanonicalGraphProjector.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Unit/Services/Graph/DashboardGraphPublicHandleTest.php backend/tests/Unit/Services/Graph/DashboardGraphExplorerCursorTest.php backend/tests/Feature/Dashboard/DashboardApiContractTest.php backend/tests/Feature/Assistants/AiAgentReadToolsTest.php backend/tests/Feature/CanonicalGraphProjectionTest.php backend/tests/Feature/Graph/CanonicalGraphTraverseNeo4jReadOnlyTest.php`.
- [ ] Prepare the task commit command, without executing it during planning: `git add backend/app/Services/Graph/DashboardGraphPublicHandle.php backend/app/Services/Graph/DashboardGraphExplorerCursor.php backend/app/Services/Graph/DashboardGraphExplorerService.php backend/app/Services/Graph/CanonicalGraphQueryService.php backend/app/Services/Graph/Neo4jCanonicalGraphProjector.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Unit/Services/Graph/DashboardGraphPublicHandleTest.php backend/tests/Unit/Services/Graph/DashboardGraphExplorerCursorTest.php backend/tests/Feature/Dashboard/DashboardApiContractTest.php backend/tests/Feature/Assistants/AiAgentReadToolsTest.php backend/tests/Feature/CanonicalGraphProjectionTest.php backend/tests/Feature/Graph/CanonicalGraphTraverseNeo4jReadOnlyTest.php && git commit -m "feat(graph): expose semantic canonical relationships safely"`.

